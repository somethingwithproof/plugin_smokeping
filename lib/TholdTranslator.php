<?php
/*
 * SPDX-FileCopyrightText: 2026 Thomas Vincent <thomasvincent@gmail.com>
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * This file is part of plugin_smokeping, a plugin for Cacti.
 * https://github.com/somethingwithproof/plugin_smokeping
 *
 * This program is free software; you can redistribute it and/or modify it
 * under the terms of the GNU General Public License as published by the Free
 * Software Foundation; either version 2 of the License, or (at your option)
 * any later version.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or
 * FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for
 * more details.
 */

namespace Somethingwithproof\Smokeping;

/**
 * Translates SmokePing *** Alerts *** loss patterns into equivalent Thold
 * high-threshold definitions on a target's loss_pct summary data source, and
 * refuses patterns that Thold cannot represent faithfully.
 *
 * Supported shapes (type = loss):
 *   1. Uniform consecutive breach over N rounds:
 *      e.g. ">0%,>0%", ">10%,>10%,>10%", ">=20%,>=20%", "==100%,==100%"
 *   2. Single-baseline transition followed by N consecutive uniform breaches:
 *      e.g. "==0%,>0%,>0%", "<=5%,>10%,>10%" (when edgetrigger is off or on,
 *      note that Thold high-threshold with trigger N fires on the Nth
 *      consecutive breaching round after a non-breaching state).
 *
 * Unsupported shapes (explicitly refused):
 *   - Non-loss types (e.g. rtt, matcher)
 *   - Wildcard/decay patterns (*N*) or unknown tokens (*U*, ==U, !=U)
 *   - Mixed or non-monotonic loss levels (e.g. ">10%,>20%", "<10%,>10%")
 *   - Strict equality below 100% (e.g. "==50%") since Thold hi-threshold is >= / >
 */
final class TholdTranslator {
	/**
	 * Extract alert definitions from a parsed SmokePing configuration root.
	 *
	 * @return array<string, array{name: string, type: string, pattern: string, comment: string, edgetrigger: bool}>
	 */
	public static function parseAlerts(SmokepingSection $root): array {
		$alertsSection = $root->child('Alerts');

		if ($alertsSection === null) {
			return [];
		}

		$alerts = [];

		foreach ($alertsSection->children as $name => $child) {
			$type    = strtolower(trim($child->value('type') ?? ''));
			$pattern = trim($child->value('pattern') ?? '');
			$comment = trim($child->value('comment') ?? '');
			$edge    = strtolower(trim($child->value('edgetrigger') ?? '')) === 'yes';

			$alerts[$name] = [
				'name'        => $name,
				'type'        => $type,
				'pattern'     => $pattern,
				'comment'     => $comment,
				'edgetrigger' => $edge,
			];
		}

		return $alerts;
	}

	/**
	 * Translate one SmokePing alert definition into a Thold threshold spec.
	 *
	 * @param array{name: string, type: string, pattern: string, comment?: string, edgetrigger?: bool} $alert
	 * @return array{
	 *     supported: bool,
	 *     alert_name: string,
	 *     smokeping_type: string,
	 *     smokeping_pattern: string,
	 *     reason: string,
	 *     data_source_name?: string,
	 *     thold_hi?: float,
	 *     thold_fail_trigger?: int,
	 *     thold_type?: int,
	 *     comparator?: string
	 * }
	 */
	public static function translate(array $alert, int $pings = 20): array {
		$name    = trim($alert['name']);
		$type    = strtolower(trim($alert['type']));
		$pattern = trim($alert['pattern']);

		if ($type !== 'loss') {
			return self::unsupported($name, $type, $pattern, sprintf('SmokePing alert type "%s" cannot be translated to a loss_pct Thold threshold; only type "loss" is supported.', $type !== '' ? $type : 'unknown'));
		}

		if ($pattern === '') {
			return self::unsupported($name, $type, $pattern, 'The SmokePing alert has an empty pattern.');
		}

		$tokens = array_values(array_map('trim', explode(',', $pattern)));

		if (count($tokens) > 20) {
			return self::unsupported($name, $type, $pattern, 'Pattern exceeds maximum supported round length of 20.');
		}

		$parsed = [];

		foreach ($tokens as $token) {
			$entry = self::parseLossToken($token);

			if ($entry === null) {
				return self::unsupported($name, $type, $pattern, sprintf('Pattern token "%s" cannot be expressed as a Thold high threshold (wildcards, unknown-value checks, and non-percentage tokens are unsupported).', $token));
			}

			$parsed[] = $entry;
		}

		// Optional single leading baseline token (e.g. ==0%, ==0%,>0%,>0% or <=X%)
		// followed by one or more identical high-breach tokens.
		$breachTokens = $parsed;
		$hasBaseline  = false;

		if (count($parsed) >= 2 && self::isBaselineToken($parsed[0])) {
			$baseline     = $parsed[0];
			$breachTokens = array_slice($parsed, 1);
			$hasBaseline  = true;

			if (!self::isHighBreachToken($breachTokens[0])) {
				return self::unsupported($name, $type, $pattern, 'Pattern does not represent a consecutive high-loss threshold after a healthy baseline.');
			}

			// The baseline must be the exact complement or lower bound of the breach threshold.
			if ($baseline['op'] === '==' && $baseline['value'] === 0.0 && $breachTokens[0]['op'] === '>' && $breachTokens[0]['value'] === 0.0) {
				// ==0%, >0%, ... : exact complement on [0, 100]
			} elseif (($baseline['op'] === '<=' || $baseline['op'] === '<') && $baseline['value'] <= $breachTokens[0]['value']) {
				// <=X%, >X%, ... : non-breaching baseline followed by breaching rounds
			} else {
				return self::unsupported($name, $type, $pattern, 'Leading baseline token does not match the subsequent high-loss threshold.');
			}
		}

		if ($breachTokens === []) {
			return self::unsupported($name, $type, $pattern, 'Pattern contains no breaching rounds.');
		}

		$first = $breachTokens[0];

		if (!self::isHighBreachToken($first)) {
			return self::unsupported($name, $type, $pattern, sprintf('Token "%s%g%%" is not a high-loss threshold.', $first['op'], $first['value']));
		}

		foreach ($breachTokens as $tok) {
			if ($tok['op'] !== $first['op'] || $tok['value'] !== $first['value']) {
				return self::unsupported($name, $type, $pattern, 'Pattern mixes different loss levels across rounds; Thold requires a single threshold across consecutive rounds.');
			}
		}

		// Compute the exact Thold high threshold value.
		// In Thold, a high threshold fires when current_value > thold_hi (strict >) or >= depending on version;
		// for discrete ping loss_pct (step = 100 / pings), we pick a safe boundary so firing is identical.
		$tholdHi = $first['value'];

		if ($first['op'] === '==') {
			// Only ==100% is monotonic high-loss (loss cannot exceed 100%).
			if ($first['value'] < 100.0) {
				return self::unsupported($name, $type, $pattern, sprintf('Equality loss pattern "==%g%%" cannot be expressed as a Thold high threshold.', $first['value']));
			}

			// Slightly below 100% (midpoint between max non-100% loss and 100%) triggers on 100% loss only.
			$step    = $pings > 0 ? 100.0 / $pings : 1.0;
			$tholdHi = round(100.0 - ($step / 2.0), 3);
		} elseif ($first['op'] === '>=') {
			$step    = $pings > 0 ? 100.0 / $pings : 1.0;
			$tholdHi = max(0.0, round($first['value'] - ($step / 2.0), 3));
		}

		return [
			'supported'          => true,
			'alert_name'         => $name,
			'smokeping_type'     => $type,
			'smokeping_pattern'  => $pattern,
			'reason'             => $hasBaseline
				? sprintf('Fires on %d consecutive rounds with loss_pct > %g%% after a non-breaching round.', count($breachTokens), $tholdHi)
				: sprintf('Fires on %d consecutive rounds with loss_pct > %g%%.', count($breachTokens), $tholdHi),
			'data_source_name'   => 'loss_pct',
			'thold_hi'           => $tholdHi,
			'thold_fail_trigger' => count($breachTokens),
			'thold_type'         => 0,
			'comparator'         => '>',
		];
	}

	/**
	 * Evaluate a SmokePing loss pattern over a sequence of recorded round loss
	 * percentages, returning whether the SmokePing alert fires on each round.
	 *
	 * @param list<float> $lossPcts
	 * @return list<bool>
	 */
	public static function evaluateSmokepingPattern(string $pattern, array $lossPcts): array {
		$tokens = array_values(array_map('trim', explode(',', $pattern)));
		$parsed = [];

		foreach ($tokens as $token) {
			$entry = self::parseLossToken($token);

			if ($entry === null) {
				throw new \InvalidArgumentException('Unsupported SmokePing pattern token: ' . $token);
			}

			$parsed[] = $entry;
		}

		$len    = count($parsed);
		$result = [];

		foreach ($lossPcts as $i => $_value) {
			if ($i + 1 < $len) {
				$result[] = false;

				continue;
			}

			$matched = true;

			for ($offset = 0; $offset < $len; $offset++) {
				$sample = $lossPcts[$i - $len + 1 + $offset];

				if (!self::matchesLossToken($parsed[$offset], $sample)) {
					$matched = false;

					break;
				}
			}

			$result[] = $matched;
		}

		return $result;
	}

	/**
	 * Evaluate a translated Thold high-threshold spec over a sequence of round
	 * loss percentages, returning whether Thold raises a new alert on each round
	 * (edge-triggered on crossing fail_trigger) or is in breach state.
	 *
	 * @param array{thold_hi: float, thold_fail_trigger: int} $spec
	 * @param list<float> $lossPcts
	 * @return list<bool>
	 */
	public static function evaluateTholdSpec(array $spec, array $lossPcts, bool $edgeOnly = false): array {
		$consecutive = 0;
		$triggered   = false;
		$result      = [];

		foreach ($lossPcts as $loss) {
			if ($loss > $spec['thold_hi']) {
				$consecutive++;
			} else {
				$consecutive = 0;
				$triggered   = false;
			}

			if ($edgeOnly) {
				$fires = !$triggered && $consecutive >= $spec['thold_fail_trigger'];

				if ($fires) {
					$triggered = true;
				}

				$result[] = $fires;
			} else {
				$result[] = $consecutive >= $spec['thold_fail_trigger'];
			}
		}

		return $result;
	}

	/**
	 * @return array{op: string, value: float}|null
	 */
	private static function parseLossToken(string $token): ?array {
		if (preg_match('/^(==|!=|>=|<=|>|<)([0-9]+(?:\.[0-9]+)?)%$/', $token, $match) !== 1) {
			return null;
		}

		$value = (float) $match[2];

		if ($value < 0.0 || $value > 100.0 || $match[1] === '!=') {
			return null;
		}

		return ['op' => $match[1], 'value' => $value];
	}

	/**
	 * @param array{op: string, value: float} $token
	 */
	private static function isBaselineToken(array $token): bool {
		return ($token['op'] === '==' && $token['value'] === 0.0)
			|| $token['op'] === '<='
			|| $token['op'] === '<';
	}

	/**
	 * @param array{op: string, value: float} $token
	 */
	private static function isHighBreachToken(array $token): bool {
		return $token['op'] === '>'
			|| $token['op'] === '>='
			|| ($token['op'] === '==' && $token['value'] === 100.0);
	}

	/**
	 * @param array{op: string, value: float} $token
	 */
	private static function matchesLossToken(array $token, float $value): bool {
		return match ($token['op']) {
			'=='    => abs($value - $token['value']) < 0.0001,
			'>'     => $value > $token['value'],
			'>='    => $value >= $token['value'],
			'<'     => $value < $token['value'],
			'<='    => $value <= $token['value'],
			default => false,
		};
	}

	/**
	 * @return array{supported: false, alert_name: string, smokeping_type: string, smokeping_pattern: string, reason: string}
	 */
	private static function unsupported(string $name, string $type, string $pattern, string $reason): array {
		return [
			'supported'         => false,
			'alert_name'        => $name,
			'smokeping_type'    => $type,
			'smokeping_pattern' => $pattern,
			'reason'            => $reason,
		];
	}
}
