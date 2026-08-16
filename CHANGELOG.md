# Changelog

All notable changes to this module are documented here. Versioning follows
[Semantic Versioning](https://semver.org/), tracked via git tags on this
repository (`composer.json` deliberately carries no hardcoded `version`
field — Composer's VCS-repository support resolves it from the tag).

## [1.2.0] - 2026-08-16

Every reporting cycle now asks the platform `GET
/api/installs/connector-version` for the currently required
`minimum_version` and `latest_version`, and compares this install's own
version against both. Below the minimum, the connector self-disables --
sync and metrics submission both stop, evaluation and buffering continue
so nothing is lost, and a persistent admin notice states the installed and
required versions until the module is upgraded. Below latest but at or
above the minimum is advisory only. A failed check never changes
self-disabled status: only an actual comparison does, so a platform outage
cannot disable a healthy install, and a disabled one recovers on its own
once upgraded. The outcome is cached in a new singleton
`watchtower_connector_version_state` table and shown in `watchtower:status`
and the Diagnostics admin page.

Replaces the update-availability check 1.1.0 bundled into the sync
response, which polled GitHub for the latest release and could only ever
advise, never enforce. Those `connector_update_available` /
`connector_latest_version` columns are dropped from
`watchtower_environment_state`; run `bin/magento setup:upgrade` after
updating.

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
