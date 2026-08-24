# Security Policy

## Reporting a vulnerability

**Do not open a public issue for a security problem.**

Report it privately through GitHub's
[private vulnerability reporting](https://github.com/reiarseni/sanctum-refresh-token/security/advisories/new)
on this repository, or by email to **reiarseni@gmail.com** with
`[security] sanctum-refresh-token` in the subject.

Please include:

- the version of the package and of Laravel and PHP,
- the relevant configuration (`config/sanctum-refresh-token.php`),
- a description of the impact,
- the smallest reproduction you can produce.

You will get an acknowledgement within **72 hours** and an assessment within
**7 days**. If the report is accepted, you will be told the intended fix and
release date before the fix is published, and credited in the advisory unless
you ask not to be.

## Supported versions

The package is pre-1.0. Only the latest released minor version receives
security fixes; there are no long-term-support branches before 1.0.

| Version | Supported          |
| ------- | ------------------ |
| 0.1.x   | :white_check_mark: |
| < 0.1   | :x:                |

Once 1.0 is released, this table will be updated to state the supported
window for the stable line.

## Scope

In scope: anything that lets a refresh token be used by a party it was not
issued to, lets a revoked or expired credential authenticate, lets one
issuance context act on another's families, or exposes a plaintext token or
client metadata that the configuration says is hashed.

Out of scope: weaknesses in Laravel Sanctum itself (report those to Laravel),
denial of service through unbounded table growth without pruning (that is
what `sanctum-refresh:prune` is for), and misconfigurations the package
documents as unsafe, such as enabling plaintext metadata storage.
