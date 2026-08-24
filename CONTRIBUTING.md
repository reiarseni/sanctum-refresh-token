# Contributing

Thank you for considering a contribution. This document states the rules a
pull request has to satisfy before it can be merged.

## Language

Everything in this repository is written in English: code, comments, commit
messages, issues, pull requests and documentation. No exceptions.

## Commit messages

Commits follow [Conventional Commits](https://www.conventionalcommits.org/en/v1.0.0/).

```
<type>(<scope>): <imperative title>

<optional body, wrapped at 72 columns>

Signed-off-by: Your Name <you@example.com>
```

### Types

| Type       | Use for                                                        |
| ---------- | -------------------------------------------------------------- |
| `feat`     | A new capability visible to a consumer of the package           |
| `fix`      | A correction of behaviour that was wrong                        |
| `perf`     | A change made for performance, with no behavioural difference   |
| `refactor` | An internal change with no behavioural difference               |
| `test`     | Adding or correcting tests only                                 |
| `docs`     | Documentation only                                              |
| `build`    | Composer manifest, packaging, published files                   |
| `ci`       | Workflow and pipeline configuration                             |
| `chore`    | Repository housekeeping that fits none of the above             |

### Scopes

| Scope        | Area                                                          |
| ------------ | ------------------------------------------------------------- |
| `issuance`   | Opening a family and minting the first pair                   |
| `rotation`   | Exchanging a refresh token for the next generation            |
| `reuse`      | Reuse detection and family revocation                         |
| `context`    | Issuance-context binding and its resolvers                    |
| `sessions`   | The session read model and its resource                       |
| `security`   | Generation, hashing, comparison, metadata handling            |
| `commands`   | Artisan commands: prune, doctor, import                       |
| `config`     | The published configuration file                              |
| `migrations` | The published schema                                          |
| `docs`       | README and the `docs/` tree                                   |
| `ci`         | Continuous integration                                        |

A commit that genuinely touches no single area may omit the scope.

### Title

The title is imperative ("add", not "added" or "adds"), lower case after the
colon, no trailing full stop, and the whole first line is at most **72
characters**.

## Developer Certificate of Origin

Every commit carries a `Signed-off-by` trailer certifying the
[DCO](https://developercertificate.org/). Add it automatically:

```bash
git commit -s -m "feat(rotation): serialise rotation with a row lock"
```

The `DCO` workflow fails any pull request containing a commit without the
trailer, and a pull request that fails it cannot merge.

## AI attribution

No commit message, issue, pull request or file may contain AI attribution
markers or a `Co-Authored-By` trailer. A CI job asserts their absence.

## Before opening a pull request

```bash
composer install
vendor/bin/pint --test
vendor/bin/phpstan analyse
vendor/bin/phpunit
```

All three must pass. Behavioural changes arrive with the tests that prove
them in the same pull request, not in a follow-up.

## Reporting a vulnerability

Do not open a public issue. Follow [SECURITY.md](SECURITY.md).
