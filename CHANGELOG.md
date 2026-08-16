# Changelog

All notable changes to this module are documented here. Versioning follows
[Semantic Versioning](https://semver.org/), tracked via git tags on this
repository (`composer.json` deliberately carries no hardcoded `version`
field — Composer's VCS-repository support resolves it from the tag).

## [1.1.0] - 2026-08-16

Every sync now reports this install's Magento version and edition, plus the
connector's own installed version. The platform echoes back, per sync,
whether the reported Magento version has reached end of life (checked
against magento.watch) and whether a newer connector release is available
-- both surfaced in `watchtower:status` and the Diagnostics admin page.
Additive, backward-compatible wire change: all three new request fields are
optional, and an older platform that doesn't return the new response fields
is read as "undetermined", never as a false "not EOL" / "no update".

## [1.0.1] - 2026-08-15

Added a copyright/license header to every source file (PHP, XML, JS).
No behavioral change.

## [1.0.0] - 2026-08-15

Initial release.
