# Release archive

How a release is made, from the pull request to the GitHub Release, is in [RELEASING.md](RELEASING.md). This page is about what the archive holds.

## Version and changelog

The version is a semantic version in `INFO` (`version = 1.0.0-rc.1`). Cacti records it as the installed version only after the plugin's migrations succeed. Every release has an entry in [CHANGELOG.md](../CHANGELOG.md) headed `## [version]`, naming the issues it closes or advances. The archive script refuses to build when `INFO` is not a semantic version or the changelog has no entry for it.

## The release archive

```sh
sh tools/make-release.sh [ref] [output-dir]      # defaults: HEAD and ./dist
```

It writes `smokeping-<version>.tar.gz` and `SHA256SUMS`. The archive extracts to a single `smokeping/` directory, so it installs with `tar -xzf smokeping-<version>.tar.gz -C <cacti>/plugins`. It is built from the committed tree with `git archive`, so an untracked or modified file cannot enter it, and it is reproducible: two builds of one commit are byte for byte equal.

It holds only what a site needs: the PHP entry points, `lib/`, `include/`, `cli/`, `scripts/`, `bin/`, `templates/`, `INFO`, `LICENSE`, `README.md`, `SECURITY.md`, `CHANGELOG.md`, the translation template `locales/po/cacti.pot` with `locales/index.php`, and `docs/`. Tests, CI files, `tools/`, the gettext build script, and contributor files are left out. A new top-level file is excluded until it is added to the allowlist in the script.

## Qualifying a release candidate

```sh
sh tests/run-release-e2e.sh <work-dir> [ref]
```

builds the archive, checks that it holds only runtime files, the POT, and documentation, that it is reproducible, that its entry points parse on PHP 8.0, and that the changelog has an entry for its version. It then installs the archive on a clean copy of Cacti 1.2.31 in a container, enables the plugin, runs its CLI and reader, upgrades over it, and uninstalls it.

CI runs the unit suite, PHPStan at level 9, `locales/build_gettext.sh --check` (the POT is current) and the end-to-end runners listed in the [failure qualification](failure-qualification.md) and [backup and restore](backup-restore.md) pages. What it cannot run, the live suite, is on the checklist in [RELEASING.md](RELEASING.md).
