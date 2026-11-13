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
 * One `rrdtool -` process shared by every read in a reader run.
 *
 * Cacti 1.2.31's rrd_init() opens its pipe write-only, with rrdtool's output
 * sent to the terminal or /dev/null, so it cannot return lastupdate or fetch
 * results. This class keeps the same one-process-per-run model with a
 * bidirectional pipe, using Cacti's configured rrdtool binary.
 */
final class RrdtoolProcess implements RrdReader {
	/** @var resource|null */
	private $process = null;

	/** @var array<int, resource> */
	private array $pipes = [];

	public function __construct(private readonly string $rrdtool, private readonly int $timeout = 30) {
	}

	public function lastUpdate(string $file): ?RrdLastUpdate {
		// rrdtool's pipe mode splits arguments on whitespace, and the paths come
		// from validated tree paths under the configured mount.
		if (preg_match('/[\s"\'\\\\]/', $file) === 1) {
			throw new \RuntimeException(sprintf('Refusing RRD path with whitespace or quotes: %s', $file));
		}

		if (!is_file($file)) {
			return null;
		}

		return RrdLastUpdate::parse($this->execute('lastupdate ' . $file));
	}

	public function close(): void {
		if ($this->process === null) {
			return;
		}

		fwrite($this->pipes[0], "quit\n");

		foreach ($this->pipes as $pipe) {
			fclose($pipe);
		}

		proc_close($this->process);

		$this->process = null;
		$this->pipes   = [];
	}

	public function __destruct() {
		$this->close();
	}

	private function execute(string $command): string {
		$this->start();

		fwrite($this->pipes[0], $command . "\n");
		fflush($this->pipes[0]);

		$output = '';

		while (($line = fgets($this->pipes[1])) !== false) {
			if (str_starts_with($line, 'OK ')) {
				return $output;
			}

			if (str_starts_with($line, 'ERROR')) {
				throw new \RuntimeException(trim($line));
			}

			$output .= $line;
		}

		$meta = stream_get_meta_data($this->pipes[1]);
		$this->close();

		throw new \RuntimeException($meta['timed_out'] ? 'rrdtool did not answer in time' : 'rrdtool exited unexpectedly');
	}

	private function start(): void {
		if ($this->process !== null) {
			return;
		}

		if (!is_file($this->rrdtool) || !is_executable($this->rrdtool)) {
			throw new \RuntimeException(sprintf('rrdtool binary %s is not an executable file', $this->rrdtool));
		}

		// LC_ALL=C keeps the decimal separator a dot, as Cacti does for fetch.
		$process = proc_open([$this->rrdtool, '-'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, ['LC_ALL' => 'C', 'PATH' => '/usr/bin:/bin']);

		if (!is_resource($process)) {
			throw new \RuntimeException('Unable to start rrdtool');
		}

		stream_set_timeout($pipes[1], $this->timeout);

		$this->process = $process;
		$this->pipes   = $pipes;
	}
}
