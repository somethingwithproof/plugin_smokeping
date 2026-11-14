<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: 2026 Thomas Vincent

namespace Somethingwithproof\Smokeping;

/**
 * Reads a SmokePing configuration file the way SmokePing's own parser
 * (Config::Grammar::Dynamic with the grammar of Smokeping::get_parser()) does,
 * without SmokePing or Perl on the Cacti side.
 *
 * Lines are trimmed, comments start at the first "#", a trailing backslash
 * joins the next line, "*** Name ***" opens a top-level section and "+",
 * "++" and deeper open nested ones, "key = value" sets a variable, and any
 * other line is a table row. "@include" reads another file in place,
 * relative to the including file, and "@define" substitutes text in later
 * lines. Inherited values, defaults and mandatory variables follow the
 * grammar; errors name the file and line as SmokePing does.
 *
 * The file is untrusted input. Its size, the length of a line before and
 * after @define substitution, the number of defines, the nesting depth, the
 * number of sections and the number and depth of includes are bounded, and an include must resolve inside an allowed directory, by
 * default the tree of the configuration file's own directory. A parse from
 * a string, such as an uploaded file, refuses @include.
 */
final class SmokepingConfigParser {
	public const MAX_FILE_BYTES    = 16777216;
	public const MAX_TOTAL_BYTES   = 67108864;
	public const MAX_LINE          = 65536;
	public const MAX_INCLUDES      = 256;
	public const MAX_INCLUDE_DEPTH = 16;
	public const MAX_DEPTH         = 32;
	public const MAX_SECTIONS      = 500000;
	public const MAX_DEFINES       = 64;

	/** @var list<string> */
	private array $allowed = [];
	private SmokepingSection $root;
	/** @var array<int, SmokepingSection> open sections by level */
	private array $stack = [];
	private int $level = 0;
	private string $file = '';
	private int $line = 0;
	/** @var array<string, string> */
	private array $defines = [];
	/** @var list<string> real paths of the files being read */
	private array $reading = [];
	private int $includes = 0;
	private int $bytes = 0;
	private int $sections = 0;
	private bool $includesAllowed = true;
	/** @var array<string, string> probe name => probe module, or '_template' */
	private array $knownProbes = [];
	/** @var array<string, string> probe name => probe module, kept when the probe becomes a template */
	private array $probeModules = [];
	/** @var array<string, list<string>> probe name => target variables a host still needs */
	private array $targetMandatory = [];
	/** @var array<string, array<string, mixed>> */
	private array $grammarCache = [];

	/**
	 * @param list<string> $allowedDirectories directories an @include may read
	 *                                        from; empty means the directory of
	 *                                        the configuration file
	 */
	public function __construct(private readonly array $allowedDirectories = []) {
	}

	/**
	 * @throws SmokepingConfigError
	 */
	public function parseFile(string $file): SmokepingSection {
		$this->reset();

		$real = realpath($file);

		if ($real === false || !is_file($real)) {
			throw new SmokepingConfigError(sprintf("can't open %s: No such file or directory", $file));
		}

		$allowed = $this->allowedDirectories === [] ? [dirname($real)] : $this->allowedDirectories;

		foreach ($allowed as $dir) {
			$dir = realpath($dir);

			if ($dir !== false && is_dir($dir)) {
				$this->allowed[] = rtrim($dir, '/');
			}
		}

		if (!$this->inside($real)) {
			throw new SmokepingConfigError(sprintf('%s is outside the allowed directories', $file));
		}

		$this->readFile($file, $real);

		return $this->finish();
	}

	/**
	 * Parse text that did not come from a file on this host, such as an
	 * upload. @include is refused because there is nothing to resolve it
	 * against.
	 *
	 * @throws SmokepingConfigError
	 */
	public function parseString(string $text, string $name): SmokepingSection {
		$this->reset();
		$this->includesAllowed = false;

		if (strlen($text) > self::MAX_FILE_BYTES) {
			throw new SmokepingConfigError(sprintf('%s is larger than %d bytes', $name, self::MAX_FILE_BYTES));
		}

		$this->bytes = strlen($text);
		$this->parseText($text, $name);

		return $this->finish();
	}

	private function reset(): void {
		$this->root            = new SmokepingSection('', SmokepingGrammar::ROOT, '', 0);
		$this->stack           = [0 => $this->root];
		$this->level           = 0;
		$this->file            = '';
		$this->line            = 0;
		$this->defines         = [];
		$this->reading         = [];
		$this->includes        = 0;
		$this->bytes           = 0;
		$this->sections        = 0;
		$this->includesAllowed = true;
		$this->knownProbes     = [];
		$this->probeModules    = [];
		$this->targetMandatory = [];
		$this->allowed         = [];
	}

	private function finish(): SmokepingSection {
		$this->gotoLevel(0, '');
		$this->fillDefaults($this->root);
		$this->checkMandatory($this->root);

		return $this->root;
	}

	private function inside(string $real): bool {
		foreach ($this->allowed as $dir) {
			if ($real === $dir || str_starts_with($real, $dir . '/')) {
				return true;
			}
		}

		return false;
	}

	private function fail(string $reason): never {
		throw new SmokepingConfigError($reason, $this->file, $this->line);
	}

	private function readFile(string $file, string $real): void {
		if (count($this->reading) >= self::MAX_INCLUDE_DEPTH) {
			$this->fail(sprintf('@include is nested deeper than %d files', self::MAX_INCLUDE_DEPTH));
		}

		$size = filesize($real);

		if ($size === false || $size > self::MAX_FILE_BYTES) {
			$this->fail(sprintf('%s is larger than %d bytes', $file, self::MAX_FILE_BYTES));
		}

		$text = @file_get_contents($real, false, null, 0, self::MAX_FILE_BYTES + 1);

		if ($text === false) {
			$this->fail(sprintf("can't open %s: %s", $file, 'Permission denied'));
		}

		if (strlen($text) > self::MAX_FILE_BYTES) {
			$this->fail(sprintf('%s is larger than %d bytes', $file, self::MAX_FILE_BYTES));
		}

		$this->bytes += strlen($text);

		if ($this->bytes > self::MAX_TOTAL_BYTES) {
			$this->fail(sprintf('the configuration and its includes are larger than %d bytes', self::MAX_TOTAL_BYTES));
		}

		$this->reading[] = $real;
		$this->parseText($text, $file);
		array_pop($this->reading);
	}

	private function parseText(string $text, string $file): void {
		$this->file = $file;
		$lines      = explode("\n", $text);

		if (end($lines) === '') {
			array_pop($lines);
		}

		$count = count($lines);

		for ($i = 0; $i < $count; $i++) {
			$this->line = $i + 1;
			$line       = $lines[$i];

			if (strlen($line) > self::MAX_LINE) {
				$this->fail(sprintf('the line is longer than %d bytes', self::MAX_LINE));
			}

			$line = self::trim($line);
			$line = (string) preg_replace('/\s*#.*$/', '', $line, 1);

			if ($line === '') {
				continue;
			}

			while (str_ends_with($line, '\\')) {
				$line = substr($line, 0, -1);

				if ($i + 1 >= $count) {
					break;
				}

				$i++;
				$this->line = $i + 1;

				if (strlen($lines[$i]) > self::MAX_LINE) {
					$this->fail(sprintf('the line is longer than %d bytes', self::MAX_LINE));
				}

				$line .= ' ' . self::trim($lines[$i]);

				if (strlen($line) > self::MAX_LINE) {
					$this->fail(sprintf('the continued line is longer than %d bytes', self::MAX_LINE));
				}
			}

			$this->parseLine($line);
		}
	}

	/**
	 * Perl's s/^\s+//; s/\s+$//.
	 */
	private static function trim(string $line): string {
		return (string) preg_replace(['/^\s+/', '/\s+$/'], '', $line);
	}

	private function parseLine(string $line): void {
		if (preg_match('/^@include\s+["\']?(.*)["\']?$/', $line, $m) === 1) {
			$this->include($m[1]);

			return;
		}

		if (preg_match('/^@define\s+(\S+)\s+(.*)$/', $line, $m) === 1) {
			// Every define is applied to every later line, so their number
			// multiplies the time a parse takes.
			if (!isset($this->defines[$m[1]]) && count($this->defines) >= self::MAX_DEFINES) {
				$this->fail(sprintf('more than %d @define lines', self::MAX_DEFINES));
			}

			$this->defines[$m[1]] = $m[2];

			return;
		}

		foreach ($this->defines as $name => $value) {
			$name  = (string) $name;
			$count = substr_count($line, $name);

			if ($count === 0) {
				continue;
			}

			// Each substitution can lengthen the line the next one works on,
			// so the result is bounded before it is built.
			if (strlen($line) + $count * (strlen($value) - strlen($name)) > self::MAX_LINE) {
				$this->fail(sprintf('the line is longer than %d bytes after @define substitution', self::MAX_LINE));
			}

			$line = str_replace($name, $value, $line);
		}

		if (preg_match('/^\*\*\*\s*(.*?)\s*\*\*\*$/', $line, $m) === 1) {
			$this->gotoLevel(1, $m[1]);

			return;
		}

		if (preg_match('/^(\++)\s*(.*)$/', $line, $m) === 1) {
			$this->gotoLevel(strlen($m[1]) + 1, $m[2]);

			return;
		}

		if (preg_match('/^(\S+)\s*=\s*(.*)$/', $line, $m) === 1) {
			$this->variable($m[1], $m[2]);

			return;
		}

		$this->tableRow($line);
	}

	private function include(string $name): void {
		if (!$this->includesAllowed) {
			$this->fail('@include is not allowed here: an uploaded file is read on its own, so paste the included files into it');
		}

		// Config::Grammar joins a relative path to the including file's directory.
		if (!str_starts_with($name, '/') && preg_match('#^(.+)/[^/]+$#', $this->file, $m) === 1) {
			$name = $m[1] . '/' . $name;
		}

		if (++$this->includes > self::MAX_INCLUDES) {
			$this->fail(sprintf('more than %d @include lines', self::MAX_INCLUDES));
		}

		$real = realpath($name);

		if ($real === false || !is_file($real)) {
			$this->fail(sprintf("can't open %s: No such file or directory", $name));
		}

		if (!$this->inside($real)) {
			$this->fail(sprintf('@include of %s is outside the allowed directories', $name));
		}

		if (in_array($real, $this->reading, true)) {
			$this->fail(sprintf('@include of %s reads a file that is already being read', $name));
		}

		[$file, $line] = [$this->file, $this->line];
		$this->readFile($name, $real);
		[$this->file, $this->line] = [$file, $line];
	}

	private function gotoLevel(int $level, string $name): void {
		if ($level > $this->level) {
			if ($level > $this->level + 1) {
				$this->fail('section nesting error');
			}

			$this->nextLevel($name);

			return;
		}

		while ($this->level > $level) {
			$this->prevLevel();
		}

		if ($level !== 0) {
			$this->prevLevel();
			$this->nextLevel($name);
		}
	}

	private function prevLevel(): void {
		$this->fillDefaults($this->stack[$this->level]);
		unset($this->stack[$this->level]);
		$this->level--;
	}

	private function nextLevel(string $name): void {
		$parent  = $this->stack[$this->level];
		$grammar = $this->grammar($parent);
		$kind    = $this->searchSection($grammar, $name);

		if ($this->level + 1 > self::MAX_DEPTH) {
			$this->fail(sprintf('sections are nested deeper than %d levels', self::MAX_DEPTH));
		}

		if (++$this->sections > self::MAX_SECTIONS) {
			$this->fail(sprintf('more than %d sections', self::MAX_SECTIONS));
		}

		$path  = $parent->path === '' ? $name : $parent->path . '/' . $name;
		$child = new SmokepingSection($name, $kind, $path, $this->level + 1);

		if ($kind === SmokepingGrammar::TARGET) {
			$child->probe         = $parent->probe;
			$child->hostMandatory = $parent->hostMandatory;
		}

		foreach ($this->inheritedNames($child, $parent) as $var) {
			if (isset($parent->vars[$var])) {
				$child->vars[$var]      = $parent->vars[$var];
				$child->inherited[$var] = true;
			}
		}

		if ($parent->hasKey($name)) {
			$this->fail('section or variable already exists');
		}

		if ($kind === SmokepingGrammar::TARGET || $kind === SmokepingGrammar::TARGETS) {
			$parent->orderedChild = true;
		}

		$parent->children[$name] = $child;
		$this->stack[++$this->level] = $child;

		$this->enter($child, $parent);
	}

	/**
	 * The _dyn and section _sub hooks of SmokePing's grammar.
	 */
	private function enter(SmokepingSection $section, SmokepingSection $parent): void {
		switch ($section->kind) {
			case SmokepingGrammar::PROBE:
				if (!isset(ProbeCatalog::PROBES[$section->name])) {
					$this->fail(sprintf("require Smokeping::probes::%s failed: there is no SmokePing probe module named '%s'", $section->name, $section->name));
				}

				$section->probe     = $section->name;
				$section->mandatory = ProbeCatalog::PROBES[$section->name]['probe_mandatory'];

				$this->knownProbes[$section->name]     = '_template';
				$this->probeModules[$section->name]    = $section->name;
				$this->targetMandatory[$section->name] = ProbeCatalog::PROBES[$section->name]['target_mandatory'];

				break;

			case SmokepingGrammar::SUBPROBE:
				$module = (string) $parent->probe;

				// A probe with instances is only a template for them.
				$parent->mandatory = [];

				if (($this->knownProbes[$parent->name] ?? null) === '_template') {
					unset($this->knownProbes[$parent->name]);
				}

				$section->probe     = $module;
				$section->mandatory = ProbeCatalog::PROBES[$module]['probe_mandatory'];

				$this->knownProbes[$section->name]     = $module;
				$this->probeModules[$section->name]    = $module;
				$this->targetMandatory[$section->name] = $this->targetMandatory[$parent->name] ?? [];

				break;

			case SmokepingGrammar::TARGET:
				if ($section->name === 'PROBE_CONF') {
					$this->fail('PROBE_CONF sections are neither needed nor supported any longer. Please see the smokeping_upgrade document.');
				}

				break;

			default:
				$section->mandatory = SmokepingGrammar::section($section->kind)['mandatory'] ?? [];
		}
	}

	/**
	 * @return list<string>
	 */
	private function inheritedNames(SmokepingSection $child, SmokepingSection $parent): array {
		return match ($child->kind) {
			SmokepingGrammar::TARGET   => array_merge(SmokepingGrammar::TARGET_INHERITED, self::exactNames($this->targetVars($child->probe))),
			SmokepingGrammar::SUBPROBE => self::exactNames(self::map($this->grammar($parent)['vars'] ?? null)),
			SmokepingGrammar::PROBE    => [],
			default                    => SmokepingGrammar::section($child->kind)['inherited'] ?? [],
		};
	}

	/**
	 * @return array<mixed>
	 */
	private static function map(mixed $value): array {
		return is_array($value) ? $value : [];
	}

	/**
	 * @param array<mixed> $vars
	 *
	 * @return list<string>
	 */
	private static function exactNames(array $vars): array {
		return array_values(array_filter(array_map('strval', array_keys($vars)), fn (string $name) => !self::isPattern($name)));
	}

	private static function isPattern(string $name): bool {
		return strlen($name) > 1 && $name[0] === '/' && str_ends_with($name, '/');
	}

	/**
	 * @param array<string, mixed> $grammar
	 */
	private function searchSection(array $grammar, string $name): string {
		$sections = $grammar['sections'] ?? null;

		if (!is_array($sections)) {
			$this->fail('no sections are allowed');
		}

		foreach ($sections as $key => $kind) {
			if ((string) $key === $name && is_string($kind)) {
				return $kind;
			}
		}

		foreach ($sections as $key => $kind) {
			if (is_string($kind) && self::isPattern((string) $key) && self::matches(substr((string) $key, 1, -1), $name)) {
				return $kind;
			}
		}

		$this->fail(sprintf("unknown section '%s'", $name));
	}

	/**
	 * Perl's $value =~ /^$re$/ for a pattern from the grammar. Patterns never
	 * come from the file being read.
	 */
	private static function matches(string $re, string $value): bool {
		$result = preg_match("\x01^" . $re . "$\x01", $value);

		if ($result === false) {
			throw new SmokepingConfigError('a value is too complex to check');
		}

		return $result === 1;
	}

	private function variable(string $key, string $value): void {
		$section = $this->stack[$this->level];

		if ($section->hasKey($key)) {
			if (!isset($section->inherited[$key])) {
				$this->fail('variable already defined');
			}

			unset($section->inherited[$key], $section->vars[$key]);
		}

		$grammar = $this->grammar($section);
		$vars    = $grammar['vars'] ?? null;

		if (!is_array($vars)) {
			$this->fail('no variables are allowed');
		}

		$spec = $this->searchVariable($vars, $key);

		if (isset($spec['re']) && is_string($spec['re'])) {
			try {
				$ok = self::matches($spec['re'], $value);
			} catch (SmokepingConfigError) {
				$this->fail(sprintf("the value of '%s' is too complex to check", $key));
			}

			if (!$ok) {
				$this->fail(isset($spec['error']) && is_string($spec['error']) ? $spec['error'] : sprintf("syntax error in value of '%s'", $key));
			}
		}

		if (isset($spec['check']) && is_string($spec['check'])) {
			$error = $this->check($spec['check'], $value, $section);

			if ($error !== null) {
				$this->fail($error);
			}
		}

		if (isset($spec['range']) && is_array($spec['range'])) {
			[$min, $max, $error] = $spec['range'];
			$n = self::perlNumber($value);

			if ($n < $min || ($max !== null && $n > $max)) {
				$this->fail(is_string($error) ? $error : 'value out of range');
			}
		}

		$this->changed($section, $key, $value);

		$section->vars[$key] = $value;

		if ($section->kind === SmokepingGrammar::TARGET) {
			$section->varlist[] = $key;
		}
	}

	/**
	 * @param array<mixed> $vars
	 *
	 * @return array<mixed>
	 */
	private function searchVariable(array $vars, string $key): array {
		if (isset($vars[$key]) && is_array($vars[$key])) {
			return $vars[$key];
		}

		foreach ($vars as $name => $spec) {
			if (self::isPattern((string) $name) && self::matches(substr((string) $name, 1, -1), $key) && is_array($spec)) {
				return $spec;
			}
		}

		$this->fail(sprintf("unknown variable '%s'", $key));
	}

	/**
	 * The _dyn hooks that run when a variable is set explicitly.
	 */
	private function changed(SmokepingSection $section, string $key, string $value): void {
		switch ($section->kind) {
			case SmokepingGrammar::PROBE:
			case SmokepingGrammar::SUBPROBE:
				// A target variable given for the probe is no longer mandatory on each host.
				$this->targetMandatory[$section->name] = array_values(array_diff($this->targetMandatory[$section->name] ?? [], [$key]));

				break;

			case SmokepingGrammar::TARGETS:
			case SmokepingGrammar::TARGET:
				if ($key === 'probe') {
					$section->probe         = $value;
					$section->hostMandatory = $this->targetMandatory[$value] ?? [];
				} elseif ($key === 'host' && $section->kind === SmokepingGrammar::TARGET && $section->hostMandatory !== null) {
					$section->mandatory = $section->hostMandatory;
				}

				break;
		}
	}

	private function check(string $check, string $value, SmokepingSection $section): ?string {
		$n = self::perlNumber($value);

		return match ($check) {
			'int1'       => $n == self::perlInt($n) && $n >= 1 ? null : 'must be an integer >= 1',
			'pings3'     => $n < 3 ? 'ERROR: The pings value must be at least 3.' : null,
			'xff'        => $n > 0 && $n <= 1 ? null : 'Xff must be between 0 and 1',
			'width'      => $n >= 10 && self::perlInt($n) == $n ? null : 'width must be be an integer >= 10',
			'height'     => $n >= 10 && self::perlInt($n) == $n ? null : 'height must be an integer >= 10',
			'tolerance'  => $n <= 1 ? 'tolerance must be larger than 1' : null,
			'influxport' => $n > 0 && $n < 65536 ? null : 'Invalid InfluxDB port (needs to be between 1-65535)',
			'probe'      => isset($this->knownProbes[$value]) ? null : sprintf('probe %s missing from the Probes section', $value),
			'probe_target' => $this->checkTargetProbe($value, $section),
			default      => null,
		};
	}

	private function checkTargetProbe(string $value, SmokepingSection $section): ?string {
		if (!isset($this->knownProbes[$value])) {
			return sprintf('probe %s missing from the Probes section', $value);
		}

		$common = array_diff(SmokepingGrammar::TARGET_COMMON_VARS, ['host']);

		foreach ($section->varlist as $var) {
			if (!in_array($var, $common, true)) {
				return 'probe must be defined before the host or any probe variables';
			}
		}

		return null;
	}

	/**
	 * The number Perl reads from the start of a string, as == and < see it.
	 */
	private static function perlNumber(string $value): float {
		if (preg_match('/^\s*([-+]?(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][-+]?\d+)?)/', $value, $m) === 1) {
			return (float) $m[1];
		}

		if (preg_match('/^\s*([-+]?)(inf(?:inity)?|nan)/i', $value, $m) === 1) {
			$number = strtolower($m[2]) === 'nan' ? NAN : INF;

			return $m[1] === '-' ? -$number : $number;
		}

		return 0.0;
	}

	private static function perlInt(float $n): float {
		return $n >= 0 ? floor($n) : ceil($n);
	}

	private function tableRow(string $line): void {
		$section = $this->stack[$this->level];
		$table   = $this->grammar($section)['table'] ?? null;

		if (!is_array($table)) {
			$this->fail('table syntax error');
		}

		$row     = $this->quoteSplit($line);
		$columns = is_int($table['columns'] ?? null) ? $table['columns'] : 0;
		$cols    = self::map($table['cols'] ?? null);

		if (count($row) !== $columns) {
			$this->fail(sprintf('row must have %d columns (has %d)', $columns, count($row)));
		}

		foreach ($row as $n => $cell) {
			$spec = self::map($cols[$n] ?? null);

			if (isset($spec['re']) && is_string($spec['re']) && !self::matches($spec['re'], $cell)) {
				$this->fail(isset($spec['error']) && is_string($spec['error']) ? sprintf('column %d: %s', $n + 1, $spec['error']) : sprintf('syntax error in column %d', $n + 1));
			}

			if (isset($spec['check']) && is_string($spec['check'])) {
				$error = $this->check($spec['check'], $cell, $section);

				if ($error !== null) {
					$this->fail($error);
				}
			}
		}

		$section->table[] = $row;
	}

	/**
	 * Config::Grammar's _quotesplit: words, "double" or 'single' quoted, with
	 * backslash escapes.
	 *
	 * @return list<string>
	 */
	private function quoteSplit(string $line): array {
		$items = [];

		while ($line !== '') {
			if (($r = preg_match('/^"((?:\\\\.|[^"])*)"\s*/', $line, $m)) === 1 || ($r !== false && ($r = preg_match("/^'((?:\\\\.|[^'])*)'\\s*/", $line, $m)) === 1) || ($r !== false && ($r = preg_match('/^((?:\\\\.|[^\s])*)(?:\s+|$)/', $line, $m)) === 1)) {
				$items[] = (string) preg_replace('/\\\\(.)/', '$1', $m[1]);
				$line    = substr($line, strlen($m[0]));

				continue;
			}

			$this->fail('the table row is too complex to read');
		}

		return $items;
	}

	private function fillDefaults(SmokepingSection $section): void {
		$vars = $this->grammar($section)['vars'] ?? null;

		if (!is_array($vars)) {
			return;
		}

		foreach ($vars as $name => $spec) {
			$name = (string) $name;

			if (self::isPattern($name) || $section->hasKey($name) || !is_array($spec) || !isset($spec['default']) || !is_string($spec['default'])) {
				continue;
			}

			$section->vars[$name] = $spec['default'];
		}
	}

	private function checkMandatory(SmokepingSection $section): void {
		foreach ($section->mandatory as $name) {
			if (!isset($section->vars[$name]) && !isset($section->children[$name])) {
				throw new SmokepingConfigError($name === '_table' ? 'mandatory table not defined' : sprintf("mandatory variable '%s' not defined", $name), $this->file, 0, $section->path);
			}
		}

		foreach ($section->children as $child) {
			$this->checkMandatory($child);
		}
	}

	/**
	 * The grammar in force for a section, including the probe-dependent
	 * variables of probe and target sections.
	 *
	 * @return array<string, mixed>
	 */
	private function grammar(SmokepingSection $section): array {
		$key = $section->kind . '|' . ($section->probe ?? '');

		if (isset($this->grammarCache[$key])) {
			return $this->grammarCache[$key];
		}

		$keyd = '/' . SmokepingGrammar::KEYD_RE . '/';

		switch ($section->kind) {
			case SmokepingGrammar::PROBE:
			case SmokepingGrammar::SUBPROBE:
				$probe = ProbeCatalog::PROBES[(string) $section->probe];
				$vars  = [];

				foreach ($probe['probe_vars'] + $probe['target_vars'] as $name => $spec) {
					$vars[$name] = self::probeSpec($spec, true);
				}

				$grammar = ['vars' => $vars];

				if ($section->kind === SmokepingGrammar::PROBE) {
					$grammar['sections'] = [$keyd => SmokepingGrammar::SUBPROBE];
				}

				break;

			case SmokepingGrammar::TARGETS:
				$grammar = SmokepingGrammar::section(SmokepingGrammar::TARGETS);
				$grammar['vars'] = ($grammar['vars'] ?? []) + $this->targetVars($section->probe);
				$grammar['sections'] = [$keyd => SmokepingGrammar::TARGET];

				break;

			case SmokepingGrammar::TARGET:
				$grammar = ['vars' => array_merge(SmokepingGrammar::targetCommon(), $this->targetVars($section->probe)), 'sections' => [$keyd => SmokepingGrammar::TARGET]];

				break;

			default:
				$grammar = SmokepingGrammar::section($section->kind);
		}

		return $this->grammarCache[$key] = $grammar;
	}

	/**
	 * Target variables of a probe as the Targets section sees them: without
	 * the defaults, which apply only in the Probes section.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function targetVars(?string $probe): array {
		$module = $probe === null ? null : ($this->probeModules[$probe] ?? null);

		if ($module === null || !isset(ProbeCatalog::PROBES[$module])) {
			return [];
		}

		$vars = [];

		foreach (ProbeCatalog::PROBES[$module]['target_vars'] as $name => $spec) {
			$vars[$name] = self::probeSpec($spec, false);
		}

		return $vars;
	}

	/**
	 * @param array{re: ?string, error: ?string, default: ?string, range?: array{int, ?int, string}} $spec
	 *
	 * @return array<string, mixed>
	 */
	private static function probeSpec(array $spec, bool $withDefault): array {
		$out = ['re' => $spec['re'], 'error' => $spec['error']];

		if ($withDefault && $spec['default'] !== null) {
			$out['default'] = $spec['default'];
		}

		if (isset($spec['range'])) {
			$out['range'] = $spec['range'];
		}

		return $out;
	}
}
