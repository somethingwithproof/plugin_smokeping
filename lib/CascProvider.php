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
	 * Register the SmokePing CasC provider with a 1-argument ProviderRegistrar
	 * or return the canonical plain-array descriptor.
	 *
	 * @param mixed $providers ProviderRegistrar instance or optional accumulator array.
	 * @return mixed Registered descriptor array or updated accumulator array.
	 */
	public function register($providers = null) {
		$descriptor = $this->descriptor();

		if (is_object($providers) && method_exists($providers, 'register')) {
			$providers->register($descriptor);

			return $descriptor;
		}

		if (is_array($providers)) {
			$providers[self::SLUG] = $descriptor;

			return $providers;
		}

		return $descriptor;
	}

	/**
	 * Build the canonical plain-array CasC provider descriptor.
	 *
	 * @return array<string, mixed>
	 */
	public function descriptor(): array {
		return [
			'slug'          => self::SLUG,
			'schemaVersion' => '1.0',
			'export'        => fn (array $options = []): array => $this->exportData($options),
			'validate'      => fn (array $desired): array => $this->validateData($desired),
			'plan'          => fn (array $desired, array $options = []): array => $this->planData($desired, $options),
			'apply'         => fn (array $desired, string $planHash = '', array $options = []): array => $this->applyData($desired, $planHash, $options),
		];
	}

	/**
	 * Export SmokePing declarative configuration as a plain associative array.
	 *
	 * @param array<string, mixed> $options Optional export options.
	 * @return array{schemaVersion: string, config: array<string, mixed>, reference: array<string, mixed>}
	 */
	public function exportData(array $options = []): array {
		unset($options);
		$data      = $this->service->exportArray();
		$reference = [];

		if (isset($data['reference_settings']) && is_array($data['reference_settings'])) {
			/** @var array<string, mixed> $refSettings */
			$refSettings = $data['reference_settings'];
			$reference   = $refSettings;
			unset($data['reference_settings']);
		}

		return [
			'schemaVersion' => '1.0',
			'config'        => $data,
			'reference'     => $reference,
		];
	}

	/**
	 * Validate a desired SmokePing configuration payload.
	 *
	 * @param array<string, mixed> $desired Desired declarative payload.
	 * @return list<string> Empty list when valid, or error strings when invalid.
	 */
	public function validateData(array $desired): array {
		try {
			$document = self::decodeArray($desired);
			$plan     = $this->service->plan($document);

			return $plan->errors;
		} catch (ConfigException $e) {
			return $e->lines();
		}
	}

	/**
	 * Compute a declarative change plan for a desired SmokePing payload.
	 *
	 * @param array<string, mixed> $desired Desired declarative payload.
	 * @param array<string, mixed> $options Optional planner options.
	 * @return list<array<string, mixed>>
	 */
	public function planData(array $desired, array $options = []): array {
		unset($options);

		try {
			$document = self::decodeArray($desired);
			$plan     = $this->service->plan($document);

			if ($plan->hasErrors()) {
				$items = [];

				foreach ($plan->errors as $error) {
					$items[] = [
						'operation' => 'error',
						'key'       => self::SLUG,
						'message'   => $error,
					];
				}

				return $items;
			}

			if ($plan->isEmpty()) {
				return [];
			}

			$items  = [];
			$planId = $plan->id();

			foreach ($plan->lines() as $line) {
				$items[] = [
					'operation' => 'update',
					'key'       => self::SLUG,
					'message'   => $line,
					'plan_hash' => $planId,
				];
			}

			return $items;
		} catch (ConfigException $e) {
			$items = [];

			foreach ($e->lines() as $error) {
				$items[] = [
					'operation' => 'error',
					'key'       => self::SLUG,
					'message'   => $error,
				];
			}

			return $items;
		}
	}

	/**
	 * Apply a desired SmokePing configuration payload with plan confirmation.
	 *
	 * @param array<string, mixed> $desired  Desired declarative payload.
	 * @param string               $planHash Confirmed plan hash.
	 * @param array<string, mixed> $options  Optional apply options (supports int 'user_id').
	 * @return array{ok: bool, applied: bool, errors: list<string>}
	 */
	public function applyData(array $desired, string $planHash = '', array $options = []): array {
		$userId = isset($options['user_id']) && is_int($options['user_id']) ? $options['user_id'] : 0;

		try {
			$document = self::decodeArray($desired);
			$plan     = $this->service->plan($document);

			if ($plan->hasErrors()) {
				return [
					'ok'      => false,
					'applied' => false,
					'errors'  => $plan->errors,
				];
			}

			$confirmId = $planHash !== '' ? $planHash : '';
			$refusals  = $this->service->apply($document, $confirmId, $userId, $this->clock->now());

			return [
				'ok'      => $refusals === [],
				'applied' => $refusals === [],
				'errors'  => $refusals,
			];
		} catch (ConfigException $e) {
			return [
				'ok'      => false,
				'applied' => false,
				'errors'  => $e->lines(),
			];
		}
	}

	/**
	 * Decode a plain-array CasC payload into a validated ConfigDocument.
	 *
	 * @param array<string, mixed> $desired Plain-array payload or envelope with 'config'.
	 * @return ConfigDocument
	 */
	private static function decodeArray(array $desired): ConfigDocument {
		$payload = array_key_exists('config', $desired) && is_array($desired['config']) ? $desired['config'] : $desired;

		return ConfigCodec::parse(ConfigCodec::YAML, YamlWriter::write($payload));
	}
}
