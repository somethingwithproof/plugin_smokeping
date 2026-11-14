<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 | of the License, or (at your option) any later version.                  |
 |                                                                         |
 | This program is distributed in the hope that it will be useful,         |
 | but WITHOUT ANY WARRANTY; without even the implied warranty of          |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the           |
 | GNU General Public License for more details.                            |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
 | This code is designed, written, and maintained by the Cacti Group. See  |
 | about.php and/or the AUTHORS file for specific developer information.   |
 +-------------------------------------------------------------------------+
 | http://www.cacti.net/                                                   |
 +-------------------------------------------------------------------------+
*/

namespace Cacti\Smokeping;

/**
 * Reads and writes the configuration file in either format. YAML is the
 * default. Both formats go through the same schema check, so a document means
 * the same thing whichever way it arrived.
 */
final class ConfigCodec {
	public const YAML = 'yaml';
	public const XML  = 'xml';

	public const MAX_BYTES = 8388608;

	public static function isFormat(string $format): bool {
		return $format === self::YAML || $format === self::XML;
	}

	public static function parse(string $format, string $text): ConfigDocument {
		return match ($format) {
			self::YAML => ConfigSchema::build(YamlReader::parse($text, self::MAX_BYTES)),
			self::XML  => ConfigSchema::build(ConfigXml::parse($text, self::MAX_BYTES)),
			default    => throw new ConfigException(sprintf('Unknown format "%s"; use yaml or xml', $format)),
		};
	}

	/**
	 * Read a file no larger than the size limit. The size is checked before
	 * the content is read.
	 */
	public static function readFile(string $path): string {
		if (!is_file($path) || !is_readable($path)) {
			throw new ConfigException(sprintf('Cannot read %s', $path));
		}

		$size = filesize($path);

		if ($size === false || $size > self::MAX_BYTES) {
			throw new ConfigException(sprintf('%s is larger than the %d byte limit', $path, self::MAX_BYTES));
		}

		$text = file_get_contents($path, false, null, 0, self::MAX_BYTES + 1);

		if ($text === false || strlen($text) > self::MAX_BYTES) {
			throw new ConfigException(sprintf('Cannot read %s within the %d byte limit', $path, self::MAX_BYTES));
		}

		return $text;
	}

	/**
	 * Read at most the size limit from an open stream, such as standard input.
	 *
	 * @param resource $stream
	 */
	public static function readStream($stream): string {
		$text = stream_get_contents($stream, self::MAX_BYTES + 1);

		if ($text === false || strlen($text) > self::MAX_BYTES) {
			throw new ConfigException(sprintf('The input is larger than the %d byte limit', self::MAX_BYTES));
		}

		return $text;
	}

	public static function write(string $format, ConfigDocument $document): string {
		$data = self::toArray($document);

		return match ($format) {
			self::YAML => YamlWriter::write($data),
			self::XML  => ConfigXml::write($data),
			default    => throw new ConfigException(sprintf('Unknown format "%s"; use yaml or xml', $format)),
		};
	}

	/**
	 * The document as the nested array both writers take. Empty collections
	 * and empty optional text are left out; reading them back restores the
	 * defaults.
	 *
	 * @return array<string, mixed>
	 */
	public static function toArray(ConfigDocument $document): array {
		$data = ['schema_version' => ConfigDocument::VERSION];

		if ($document->settings !== []) {
			$data['settings'] = $document->settings;
		}

		// Shown for reference; an import reports a difference and writes nothing.
		if ($document->reference !== []) {
			$data['reference_settings'] = $document->reference;
		}

		$targets = [];

		foreach ($document->targets as $target) {
			$entry = ['path' => $target->path->value, 'poller_id' => $target->pollerId, 'host' => $target->host, 'probe' => $target->probe];

			foreach (['menu' => $target->menu, 'title' => $target->title] as $name => $text) {
				if ($text !== '') {
					$entry[$name] = $text;
				}
			}

			if ($target->slaves !== []) {
				$entry['slaves'] = $target->slaves;
			}

			$entry += [
				'nomasterpoll' => $target->noMasterPoll,
				'step'         => $target->step,
				'pings'        => $target->pings,
				'mode'         => $target->mode->value,
				'admin_state'  => $target->adminState->value,
				'present'      => $target->present,
			];

			$targets[] = $entry;
		}

		if ($targets !== []) {
			$data['targets'] = $targets;
		}

		$mappings = [];

		foreach ($document->mappings as $mapping) {
			$mappings[] = [
				'target'    => $mapping->path->value,
				'poller_id' => $mapping->pollerId,
				'device'    => ['hostname' => $mapping->hostname, 'description' => $mapping->description],
			];
		}

		if ($mappings !== []) {
			$data['mappings'] = $mappings;
		}

		return $data;
	}
}
