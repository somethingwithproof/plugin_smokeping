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
 * Runs bin/smokeping-inventory.pl, which loads SmokePing's parser, and
 * decodes its JSON.
 *
 * The command is passed to proc_open() as an argument list, so no shell sees
 * the configured paths.
 */
final class ScriptInventoryReader implements InventoryReader {
	public function __construct(
		private readonly string $perlBinary,
		private readonly string $script,
		private readonly string $configFile,
		private readonly string $libDir,
		private readonly int $timeout = 120,
	) {
	}

	public function read(int $pollerId): Inventory {
		if (!is_file($this->perlBinary) || !is_executable($this->perlBinary)) {
			throw new InventoryError(sprintf('Perl binary %s is not an executable file', $this->perlBinary));
		}

		$command = [$this->perlBinary, $this->script, '--config=' . $this->configFile];

		if ($this->libDir !== '') {
			$command[] = '--libdir=' . $this->libDir;
		}

		$process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

		if (!is_resource($process)) {
			throw new InventoryError('Unable to start the inventory script');
		}

		stream_set_blocking($pipes[1], false);
		stream_set_blocking($pipes[2], false);

		$stdout   = '';
		$stderr   = '';
		$deadline = microtime(true) + $this->timeout;

		while (true) {
			$read   = [$pipes[1], $pipes[2]];
			$write  = null;
			$except = null;

			if (stream_select($read, $write, $except, 1) === false) {
				break;
			}

			foreach ($read as $stream) {
				$chunk = (string) fread($stream, 65536);

				if ($stream === $pipes[1]) {
					$stdout .= $chunk;
				} else {
					$stderr .= $chunk;
				}
			}

			if (feof($pipes[1]) && feof($pipes[2])) {
				break;
			}

			if (microtime(true) > $deadline) {
				proc_terminate($process);
				fclose($pipes[1]);
				fclose($pipes[2]);
				proc_close($process);

				throw new InventoryError(sprintf('Inventory script exceeded %d seconds', $this->timeout));
			}
		}

		fclose($pipes[1]);
		fclose($pipes[2]);

		$status = proc_close($process);

		if ($status !== 0) {
			$message = trim($stderr) !== '' ? trim($stderr) : 'no error output';

			throw new InventoryError(sprintf('Inventory script exited with status %d: %s', $status, mb_substr($message, 0, 900)));
		}

		return Inventory::fromJson($stdout, $pollerId);
	}
}
