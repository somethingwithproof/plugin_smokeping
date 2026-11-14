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
 * XML form of the configuration file. Reads with DOMDocument and no network,
 * no DTD loading, and no entity substitution, and refuses any DOCTYPE, so a
 * file cannot read local files, reach a host, or expand entities. Only the
 * elements of the schema are accepted; the result is the same tree the YAML
 * reader produces, so ConfigSchema checks both.
 *
 *   <smokeping-config schema_version="1">
 *     <settings><setting name="smokeping_poller_id">1</setting></settings>
 *     <targets>
 *       <target path="Europe/Paris" poller_id="1" host="paris.example.net" ...>
 *         <slave>site-b</slave>
 *       </target>
 *     </targets>
 *     <mappings>
 *       <mapping target="Europe/Paris" poller_id="1"><device hostname="..." description="..."/></mapping>
 *     </mappings>
 *   </smokeping-config>
 */
final class ConfigXml {
	public const MAX_BYTES = 8388608;
	public const MAX_DEPTH = 8;

	private const ROOT = 'smokeping-config';

	/** @var array<string, int> */
	private array $lines = [];

	public static function parse(string $text, int $maxBytes = self::MAX_BYTES, int $maxDepth = self::MAX_DEPTH): ConfigTree {
		if (strlen($text) > $maxBytes) {
			throw new ConfigException(sprintf('The file is larger than the %d byte limit', $maxBytes));
		}

		if (preg_match('//u', $text) !== 1) {
			throw new ConfigException('The file is not valid UTF-8');
		}

		// A DOCTYPE is the only way a document declares entities or an external
		// subset, so refuse it before libxml sees it, whatever the flags are.
		if (preg_match('/<!(DOCTYPE|ENTITY)/i', $text) === 1) {
			throw new ConfigException('A DOCTYPE or entity declaration is not allowed');
		}

		$previous = libxml_use_internal_errors(true);

		try {
			$dom = new \DOMDocument();

			if (!$dom->loadXML($text, LIBXML_NONET | LIBXML_NOBLANKS)) {
				$error = libxml_get_last_error();

				throw new ConfigException(trim($error === false ? 'The XML is not well formed' : $error->message), $error === false || $error->line < 1 ? null : $error->line);
			}
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors($previous);
		}

		if ($dom->doctype !== null) {
			throw new ConfigException('A DOCTYPE is not allowed');
		}

		$root = $dom->documentElement;

		if ($root === null || $root->nodeName !== self::ROOT) {
			throw new ConfigException(sprintf('The root element must be <%s>', self::ROOT), $root?->getLineNo());
		}

		self::checkDepth($root, 1, $maxDepth);

		return (new self())->tree($root);
	}

	/**
	 * @param array<array-key, mixed> $data the tree ConfigCodec::toArray() builds
	 */
	public static function write(array $data): string {
		$dom               = new \DOMDocument('1.0', 'UTF-8');
		$dom->formatOutput = true;

		$root = $dom->createElement(self::ROOT);

		$dom->appendChild($root);
		$root->setAttribute('schema_version', (string) self::scalar($data['schema_version'] ?? ConfigDocument::VERSION));

		foreach (['settings', 'reference_settings'] as $section) {
			$settings = $data[$section] ?? [];

			if (is_array($settings) && $settings !== []) {
				$parent = $root->appendChild($dom->createElement($section));

				foreach ($settings as $name => $value) {
					$element = $dom->createElement('setting');

					$element->setAttribute('name', (string) $name);
					$element->appendChild($dom->createTextNode(self::scalar($value)));
					$parent?->appendChild($element);
				}
			}
		}

		$targets = $data['targets'] ?? [];

		if (is_array($targets) && $targets !== []) {
			$parent = $root->appendChild($dom->createElement('targets'));

			foreach ($targets as $target) {
				if (!is_array($target)) {
					continue;
				}

				$element = $dom->createElement('target');

				foreach ($target as $field => $value) {
					if ($field === 'slaves' && is_array($value)) {
						foreach ($value as $slave) {
							$child = $dom->createElement('slave');

							$child->appendChild($dom->createTextNode(self::scalar($slave)));
							$element->appendChild($child);
						}

						continue;
					}

					$element->setAttribute((string) $field, self::scalar($value));
				}

				$parent?->appendChild($element);
			}
		}

		$mappings = $data['mappings'] ?? [];

		if (is_array($mappings) && $mappings !== []) {
			$parent = $root->appendChild($dom->createElement('mappings'));

			foreach ($mappings as $mapping) {
				if (!is_array($mapping)) {
					continue;
				}

				$element = $dom->createElement('mapping');
				$device  = is_array($mapping['device'] ?? null) ? $mapping['device'] : [];

				$element->setAttribute('target', self::scalar($mapping['target'] ?? ''));
				$element->setAttribute('poller_id', self::scalar($mapping['poller_id'] ?? 1));

				$child = $dom->createElement('device');

				$child->setAttribute('hostname', self::scalar($device['hostname'] ?? ''));
				$child->setAttribute('description', self::scalar($device['description'] ?? ''));
				$element->appendChild($child);
				$parent?->appendChild($element);
			}
		}

		$xml = $dom->saveXML();

		if ($xml === false) {
			throw new ConfigException('The XML could not be written');
		}

		return $xml;
	}

	private static function scalar(mixed $value): string {
		if (is_bool($value)) {
			return $value ? 'true' : 'false';
		}

		if (!is_int($value) && !is_string($value)) {
			throw new ConfigException('Only strings, integers, and booleans can be written');
		}

		$text = (string) $value;

		// XML 1.0 cannot carry these at all, not even as character references.
		if (preg_match('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', $text) === 1) {
			throw new ConfigException('A value contains a character XML 1.0 cannot carry');
		}

		return $text;
	}

	private static function checkDepth(\DOMNode $node, int $depth, int $max): void {
		if ($depth > $max) {
			throw new ConfigException(sprintf('Nesting is deeper than %d levels', $max), $node->getLineNo());
		}

		foreach ($node->childNodes as $child) {
			if ($child instanceof \DOMElement) {
				self::checkDepth($child, $depth + 1, $max);
			}
		}
	}

	private function tree(\DOMElement $root): ConfigTree {
		$data = [];

		foreach ($root->attributes as $attribute) {
			if ($attribute->nodeName !== 'schema_version') {
				throw new ConfigException(sprintf('The attribute "%s" is not allowed on <%s>', $attribute->nodeName, self::ROOT), $root->getLineNo());
			}

			$data['schema_version'] = ConfigSchema::coerce('int', $attribute->nodeValue ?? '');
			$this->lines['schema_version'] = $root->getLineNo();
		}

		foreach ($this->elements($root) as $child) {
			$name = $child->nodeName;

			if (array_key_exists($name, $data)) {
				throw new ConfigException(sprintf('<%s> appears twice', $name), $child->getLineNo());
			}

			$this->lines[$name] = $child->getLineNo();

			$data[$name] = match ($name) {
				'settings', 'reference_settings' => $this->settings($child, $name),
				'targets'  => $this->targets($child),
				'mappings' => $this->mappings($child),
				// Anything else, managed_targets and probe_profiles included, reaches
				// the schema check as a key it refuses by name.
				default    => null,
			};
		}

		return new ConfigTree($data, $this->lines);
	}

	/**
	 * @return array<string, int|bool|string>
	 */
	private function settings(\DOMElement $parent, string $section): array {
		$settings = [];

		foreach ($this->elements($parent) as $element) {
			$this->expect($element, 'setting', ['name']);

			$name = $element->getAttribute('name');

			if (isset($settings[$name])) {
				throw new ConfigException(sprintf('The setting "%s" appears twice', $name), $element->getLineNo());
			}

			$this->lines[$section . '/' . $name] = $element->getLineNo();
			$settings[$name]                  = ConfigSchema::coerce(ConfigSchema::SETTINGS[$name][0] ?? 'string', $this->leafText($element));
		}

		return $settings;
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function targets(\DOMElement $parent): array {
		$targets = [];

		foreach ($this->elements($parent) as $element) {
			$this->expect($element, 'target', null);

			$index = count($targets);
			$entry = [];

			foreach ($element->attributes as $attribute) {
				$entry[$attribute->nodeName] = ConfigSchema::coerce(ConfigSchema::TARGET_TYPES[$attribute->nodeName] ?? 'string', $attribute->nodeValue ?? '');
			}

			$slaves = [];

			foreach ($this->elements($element) as $slave) {
				$this->expect($slave, 'slave', []);

				$slaves[] = $this->leafText($slave);
			}

			if ($slaves !== []) {
				$entry['slaves'] = $slaves;
			}

			$this->lines['targets/' . $index] = $element->getLineNo();
			$targets[]                        = $entry;
		}

		return $targets;
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function mappings(\DOMElement $parent): array {
		$mappings = [];

		foreach ($this->elements($parent) as $element) {
			$this->expect($element, 'mapping', ['target', 'poller_id']);

			$index = count($mappings);
			$entry = ['target' => $element->getAttribute('target')];

			if ($element->hasAttribute('poller_id')) {
				$entry['poller_id'] = ConfigSchema::coerce('int', $element->getAttribute('poller_id'));
			}

			$this->lines['mappings/' . $index] = $element->getLineNo();

			foreach ($this->elements($element) as $device) {
				$this->expect($device, 'device', ['hostname', 'description']);

				if (isset($entry['device'])) {
					throw new ConfigException('<mapping> has more than one <device>', $device->getLineNo());
				}

				$entry['device'] = ['hostname' => $device->getAttribute('hostname'), 'description' => $device->getAttribute('description')];
			}

			$mappings[] = $entry;
		}

		return $mappings;
	}

	/**
	 * @return list<\DOMElement>
	 */
	private function elements(\DOMElement $parent): array {
		$elements = [];

		foreach ($parent->childNodes as $child) {
			if ($child instanceof \DOMElement) {
				$elements[] = $child;
			} elseif ($child instanceof \DOMText && trim($child->data) !== '') {
				throw new ConfigException(sprintf('Unexpected text inside <%s>', $parent->nodeName), $child->getLineNo());
			} elseif (!$child instanceof \DOMText && !$child instanceof \DOMComment) {
				throw new ConfigException('Only elements and comments are allowed here', $child->getLineNo());
			}
		}

		return $elements;
	}

	/**
	 * @param list<string>|null $attributes the only attributes allowed; null for the target element, whose fields the schema checks
	 */
	private function expect(\DOMElement $element, string $name, ?array $attributes): void {
		if ($element->nodeName !== $name) {
			throw new ConfigException(sprintf('Expected <%s>, found <%s>', $name, $element->nodeName), $element->getLineNo());
		}

		if ($attributes === null) {
			return;
		}

		foreach ($element->attributes as $attribute) {
			if (!in_array($attribute->nodeName, $attributes, true)) {
				throw new ConfigException(sprintf('The attribute "%s" is not allowed on <%s>', $attribute->nodeName, $name), $element->getLineNo());
			}
		}
	}

	private function leafText(\DOMElement $element): string {
		foreach ($element->childNodes as $child) {
			if ($child instanceof \DOMElement) {
				throw new ConfigException(sprintf('<%s> cannot contain elements', $element->nodeName), $child->getLineNo());
			}
		}

		return $element->textContent;
	}
}
