<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: 2026 Thomas Vincent

namespace Somethingwithproof\Smokeping;

/**
 * A target's position in the SmokePing tree, such as "Europe/Paris/gw1".
 *
 * The path is the target's identity: SmokePing stores its RRD at
 * datadir/<path>.rrd, so a rename is a new target. Section names follow
 * SmokePing's own rule, [-_0-9a-zA-Z]+, which also keeps every derived file
 * name free of "..", slashes, and shell metacharacters.
 */
final readonly class TargetPath {
	private const SEGMENT  = '/^[-_0-9a-zA-Z]+$/';
	private const LOCATION = '/^[-_0-9a-zA-Z][-_.0-9a-zA-Z]*$/';

	private function __construct(public string $value) {
	}

	public static function fromString(string $path): self {
		if ($path === '' || strlen($path) > 255) {
			throw new \InvalidArgumentException('Target path must be 1 to 255 characters');
		}

		foreach (explode('/', $path) as $segment) {
			if (preg_match(self::SEGMENT, $segment) !== 1) {
				throw new \InvalidArgumentException(sprintf('Invalid SmokePing section name in path "%s"', $path));
			}
		}

		return new self($path);
	}

	/**
	 * Slave names may contain dots (SmokePing allows them in slaves= values)
	 * but may not start with one, so "." and ".." are rejected.
	 */
	public static function isLocation(string $location): bool {
		return strlen($location) <= 64 && preg_match(self::LOCATION, $location) === 1;
	}

	/**
	 * RRD file for the master ('') or a slave location under $root.
	 */
	public function rrdFile(string $root, string $location = ''): string {
		if ($location !== '' && !self::isLocation($location)) {
			throw new \InvalidArgumentException(sprintf('Invalid location "%s"', $location));
		}

		return rtrim($root, '/') . '/' . $this->value . ($location === '' ? '' : '~' . $location) . '.rrd';
	}

	public function equals(self $other): bool {
		return $this->value === $other->value;
	}
}
