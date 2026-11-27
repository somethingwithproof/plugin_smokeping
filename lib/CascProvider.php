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
 * Configuration-as-Code provider registered through casc_register_providers.
 *
 * Bridges directly to ConfigCodec and ConfigService so CasC export, validate,
 * plan, and apply follow the exact same schema, permission checks, and
 * confirmation plan-hash contract as the SmokePing CLI and web page.
 */
final class CascProvider {
	/** Slug constant. */
	public const SLUG = 'smokeping';

	/**
	 * Create a new CascProvider instance.
	 *
	 * @param ConfigService $service Service.
	 * @param Clock $clock Clock.
	 */
	public function __construct(
		private readonly ConfigService $service,
		private readonly Clock $clock = new SystemClock(),
	) {
	}

	/**
	 * Register the SmokePing CasC provider with either an array accumulator or
	 * a registrar object exposing register().
	 *
	 * @param mixed $providers
	 * @return mixed
	 */
	public function register($providers) {
		$descriptor = $this->descriptor();

		if (is_array($providers)) {
			$providers[self::SLUG] = $descriptor;

			return $providers;
		}

		if (is_object($providers) && method_exists($providers, 'register')) {
			$providers->register(self::SLUG, $descriptor);
		}

		return $providers;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function descriptor(): array {
		return [
			'name'           => self::SLUG,
			'schema_version' => ConfigDocument::VERSION,
			'formats'        => [ConfigCodec::YAML, ConfigCodec::XML],
			'export'         => fn (string $format = ConfigCodec::YAML): string => $this->export($format),
			'validate'       => fn (string $content, string $format = ConfigCodec::YAML): array => $this->validate($content, $format),
			'plan'           => fn (string $content, string $format = ConfigCodec::YAML): array => $this->plan($content, $format),
			'apply'          => fn (string $content, string $confirmPlanId, string $format = ConfigCodec::YAML, int $userId = 0): array => $this->apply($content, $confirmPlanId, $format, $userId),
		];
	}

	/**
	 * Export.
	 *
	 * @param string $format Format.
	 * @return string
	 */
	public function export(string $format = ConfigCodec::YAML): string {
		return $this->service->export($format);
	}

	/**
	 * @return array{valid: bool, errors: list<string>}
	 */
	public function validate(string $content, string $format = ConfigCodec::YAML): array {
		try {
			$document = ConfigCodec::parse($format, $content);
			$plan     = $this->service->plan($document);

			return [
				'valid'  => !$plan->hasErrors(),
				'errors' => $plan->errors,
			];
		} catch (ConfigException $e) {
			return [
				'valid'  => false,
				'errors' => $e->lines(),
			];
		}
	}

	/**
	 * @return array{plan_id: string, empty: bool, has_errors: bool, errors: list<string>, lines: list<string>}
	 */
	public function plan(string $content, string $format = ConfigCodec::YAML): array {
		try {
			$document = ConfigCodec::parse($format, $content);
			$plan     = $this->service->plan($document);

			return [
				'plan_id'    => $plan->id(),
				'empty'      => $plan->isEmpty(),
				'has_errors' => $plan->hasErrors(),
				'errors'     => $plan->errors,
				'lines'      => $plan->lines(),
			];
		} catch (ConfigException $e) {
			return [
				'plan_id'    => '',
				'empty'      => true,
				'has_errors' => true,
				'errors'     => $e->lines(),
				'lines'      => [],
			];
		}
	}

	/**
	 * @return array{applied: bool, errors: list<string>}
	 */
	public function apply(string $content, string $confirmPlanId, string $format = ConfigCodec::YAML, int $userId = 0): array {
		try {
			$document = ConfigCodec::parse($format, $content);
			$refusals = $this->service->apply($document, $confirmPlanId, $userId, $this->clock->now());

			return [
				'applied' => $refusals === [],
				'errors'  => $refusals,
			];
		} catch (ConfigException $e) {
			return [
				'applied' => false,
				'errors'  => $e->lines(),
			];
		}
	}
}
