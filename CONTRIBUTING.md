# Contributing

This project is in the proposal stage. Most useful contributions right now are evidence for the research issues in milestone 1 and review of the requirements and open decisions.

## Before you start

- Read [docs/PLAN.md](docs/PLAN.md) and the [decision records](docs/decisions/).
- Find or open an issue first. Pull requests should reference the issue they resolve.
- A change that contradicts an accepted decision record needs a new decision issue, not a pull request.

## Issues

Use the issue forms:

| Form | Use it for |
|---|---|
| Requirement | A testable behavior the plugin must have |
| Research | A question that must be answered with evidence before design or code depends on it |
| Decision | A choice between options that needs a recorded outcome |
| Bug report | An error in the documents now, or in code later |

### Writing requirements

Each requirement issue lists numbered requirements with IDs in the form `REQ-AREA-NNN`, for example `REQ-COMPAT-001`. A requirement:

- states one observable behavior with **MUST**, **MUST NOT**, or **SHOULD**;
- can be verified by a test, a measurement, or an inspection named in its acceptance criteria;
- cites the plan section or upstream source it depends on.

IDs are never reused. When a requirement is dropped, its issue is closed as not planned and the ID stays retired. [docs/REQUIREMENTS.md](docs/REQUIREMENTS.md) is the index.

### Research issues

State the question, the method, and the evidence that closes the issue. Record results in a comment with versions, commands, and output, then close the issue. Results that change the design update [docs/PLAN.md](docs/PLAN.md) in the same pull request.

## Labels

| Prefix | Meaning |
|---|---|
| `type:` | What kind of issue it is: requirement, research, decision, epic |
| `area:` | The part of the design it touches |
| `phase:` | The release phase it belongs to: 0 research, 1 observe, 2 managed, 3 remote, or future |
| `priority:` | `blocker` stops its milestone; `high` is needed for the milestone; `normal` otherwise |
| `status:` | `blocked` by another issue, `needs-maintainer-decision` from Cacti, Maint, or Thold maintainers, or `needs-evidence` from a milestone 1 result |

GitHub's defaults `bug`, `documentation`, `duplicate`, `good first issue`, `help wanted`, and `wontfix` keep their usual meanings.

## Milestones

Milestones match the plan. Each has an epic issue whose sub-issues are the milestone's scope and whose body lists the exit evidence. A milestone closes when its exit evidence is attached to the epic, not when its issues are closed.

## Commits and pull requests

- Sign off every commit (`git commit -s`) to certify the [Developer Certificate of Origin](https://developercertificate.org/).
- Use [Conventional Commits](https://www.conventionalcommits.org/): `docs:`, `feat:`, `fix:`, `test:`, `chore:`, with the issue in the body (`Refs #12`, `Closes #12`).
- Keep pull requests to one issue where practical. Pull requests are squash-merged.
- Code, when it exists, follows Cacti conventions: tabs, single quotes, short array syntax, prepared queries, the `smokeping` text domain for strings, and PHPStan at `phpVersion` 8.2.

## Translations

Messages are marked with `__('...', 'smokeping')`. `locales/build_gettext.sh` regenerates the template `locales/po/cacti.pot`, merges it into each catalog `locales/po/<locale>.po`, and compiles `locales/LC_MESSAGES/<locale>.mo`; commit all three. CI runs `locales/build_gettext.sh --check`, which fails when a catalog misses or has a fuzzy entry, a placeholder differs, or a compiled file is out of date. A changed message therefore needs its translations updated in the same pull request. A new language is a new `<locale>.po` named as Cacti names its locales (for example `pt-BR`).

## Conduct

This project follows the [Code of Conduct](CODE_OF_CONDUCT.md).
