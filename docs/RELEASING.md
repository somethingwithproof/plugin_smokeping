# Releasing

A release is a tag. Pushing `v<version>` runs the release workflow, which checks the tag, runs the whole CI for the tagged commit, builds the archive, attests where it came from, and publishes the GitHub Release. Nothing is built or uploaded by hand.

The archive itself, its allowlist and its reproducibility are in [release.md](release.md). This page is the procedure.

## Before the release pull request

Run what GitHub's runners cannot, on the project's runner host:

- [ ] The live suite against a real SmokePing network (see [testing.md](testing.md#the-live-suite)): `sh tests/live/run-live-e2e.sh <work-dir>`. It needs root, a running SmokePing 2.8.2 master with slaves, and Docker, so it cannot run in CI. Attach its summary to the release pull request.
- [ ] The install guide's commands on the distributions the guide names, if `docs/install.md` changed since the last release: `docker run --rm --privileged -v "$PWD:/plugin:ro" <image> sh /plugin/tests/docs-commands.sh`. CI runs this on Debian 13 and AlmaLinux 9, so this step matters only for another distribution, such as Ubuntu or Fedora.
- [ ] The performance runs of [performance.md](performance.md), if the reader or the overview page changed.

## 1. The release pull request

In one pull request:

1. Set `version` in `INFO` to the new semantic version (`1.0.0`, or `1.0.0-rc.2` for a prerelease).
2. In `CHANGELOG.md`, move what is under `## [Unreleased]` into a new section `## [<version>] - YYYY-MM-DD` with today's date (for `1.0.0-rc.1`, whose section exists already, replace `Unreleased` in its heading with the date), and update the compare links at the bottom of the file: `[Unreleased]` compares the new tag with `HEAD`, and the new version links to its compare range (or, for the first release, its tag).
3. Merge it once `ci-ok` passes.

Check it locally before merging; this is the same script the workflow runs:

```sh
sh tools/release-check.sh check v<version>      # prints the version, or says what is wrong
sh tools/release-check.sh notes <version>       # the text the GitHub Release will carry
sh tests/release-tools-test.sh                  # the script's own tests
```

## 2. The tag

After the merge, from an up-to-date `main`:

```sh
git switch main && git pull --ff-only
git tag -s -a v<version> -m "smokeping <version>"
git push origin v<version>
```

The tag is signed and annotated. Sign-off (DCO) belongs on commits; the signature on the tag shows who released.

## 3. What the workflow does

`.github/workflows/release.yml`, in this order, stopping at the first failure:

| Job | What it does | Permissions |
|---|---|---|
| `check` | `tools/release-check.sh check`: the tag is `v` plus a semantic version, equals the version in `INFO`, and `CHANGELOG.md` has a section for it with a real date and some text | `contents: read` |
| `ci` | The whole CI workflow for the tagged commit, called as a reusable workflow | `contents: read` |
| `build` | `tools/make-release.sh` produces `smokeping-<version>.tar.gz` and `SHA256SUMS` from the committed tree | `contents: read` |
| `attest` | `actions/attest-build-provenance` signs the archive's provenance through Sigstore, using the workflow's OIDC identity | `id-token: write`, `attestations: write` |
| `publish` | `gh release create --verify-tag` makes the GitHub Release of that tag with the changelog section as its notes, as a prerelease when the version has a `-` part, and attaches the archive and `SHA256SUMS` | `contents: write` |

If a job fails, fix the cause, delete the tag (`git push --delete origin v<version>` and `git tag -d v<version>`), and push it again at the fixed commit. A release that was already published is not repeated: delete it on GitHub first.

## 4. Verify the result

```sh
gh release download v<version> --repo somethingwithproof/plugin_smokeping --dir verify
cd verify
sha256sum --check SHA256SUMS
gh attestation verify smokeping-<version>.tar.gz --repo somethingwithproof/plugin_smokeping
tar -tzf smokeping-<version>.tar.gz | head
```

`sha256sum --check` proves the archive is the one the workflow built. Rebuilding it yourself with `tools/make-release.sh` gives the same tar on any platform, and the same `.tar.gz` bytes on any one platform, but GNU gzip and macOS gzip compress that tar differently, so compare a download with the published `SHA256SUMS`, not with a local build on another operating system. `gh attestation verify` proves the workflow of this repository, at the tagged commit, built it. The archive extracts to one `smokeping/` directory; `tar -xzf smokeping-<version>.tar.gz -C <cacti>/plugins` installs it.

GitHub's own "Source code" archives of the tag hold the same files as the release archive (`export-ignore` in `.gitattributes`, kept in step by `tests/archive-policy-test.sh`), so either one installs.

## Recommended ruleset for `main`

Applying these is a repository setting, which is not part of this change. They are recorded here so the settings and the workflows agree.

A branch ruleset on `main`:

- **Require a pull request before merging.** At least one approval; dismiss stale approvals when new commits are pushed.
- **Require status checks to pass: `ci-ok`.** It is the one check that needs every CI job, so adding a job to the CI never means editing the ruleset. Require branches to be up to date before merging.
- **Require signed-off commits (DCO).** Every commit carries `Signed-off-by:` (`git commit -s`), enforced by the DCO check or the ruleset's commit message pattern `^[\s\S]*Signed-off-by: .+ <.+>`.
- **Block force pushes** and **restrict deletions**.
- Require linear history, so a squash or rebase merge keeps `main` readable, and a merge never adds a merge commit.

A tag ruleset for `v*`: restrict creation to the maintainers who release, and block updates and deletions, so a published version cannot be moved. If a release has to be repeated, lift the rule for that tag deliberately.

The release workflow needs no repository secret. Its tokens are the per-run `GITHUB_TOKEN` and the OIDC token Sigstore signs with.
