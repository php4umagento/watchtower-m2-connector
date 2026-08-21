# Changelog

All notable changes to this module are documented here. Versioning follows
[Semantic Versioning](https://semver.org/), tracked via git tags on this
repository (`composer.json` deliberately carries no hardcoded `version`
field — Composer's VCS-repository support resolves it from the tag).

## [1.5.0] - 2026-08-21

The platform now tags a sync rejection with `reason_code: ignored_local_domain`
when a store view's reported URL looks like a local/dev environment or a
private IP (PRD §5.9, FR28-30) rather than a live storefront. This module
surfaces that instead of leaving it buried in a debug log line: a
persistent, NOTICE-severity Magento admin notice (same mechanism as the
below-minimum-version warning) names the affected store view and clears
itself once a sync stops reporting any, backed by a new singleton-row
table (`watchtower_ignored_domain_state`). The config screen also gets a
proactive heads-up, before the merchant ever syncs, when the current
store's own base URL looks local/dev -- a connector-local heuristic,
advisory only, since the platform's real blacklist is admin-configurable
and not exposed here. Also links the config screen and README to the
platform's existing "create a project" / "find your API key" docs, which
weren't linked from the module anywhere before.

## [1.4.1] - 2026-08-19

Composer package renamed a third time, from `php4u/watchtower-m2-module-connector`
to `php4u/module-watchtower-m2-connector` -- v1.4.0 broke Magento's own
`Magento_Unit_Tests_Other` PHPUnit suite for this module entirely and
silently (0 tests collected, no error): its `vendor/*/module-*/Test/Unit`
glob (`dev/tests/unit/phpunit.xml.dist`, Magento core, not something this
repo controls) requires the vendor package directory to literally start
with `module-`, which `watchtower-m2-module-connector` does not.
`module-watchtower-m2-connector` does. Same lockstep update to
`ConnectorVersionReader::PACKAGE_NAME` as the previous two renames;
installs on either prior name read as "not Composer-managed" until
upgraded via `composer require php4u/module-watchtower-m2-connector`.
`php4u/watchtower-m2-module-connector` was never published to Packagist,
so there is no abandoned-package cleanup needed there this time.

## [1.4.0] - 2026-08-19

Composer package renamed again, from `php4u/module-connector` to
`php4u/watchtower-m2-module-connector` -- a naming preference, not a
namespace conflict this time (`php4u` itself is fine). The Magento module
identifier (`Watchtower_Connector`) and PHP namespace
(`Watchtower\Connector`) are unaffected; only the Composer package name
changes. `ConnectorVersionReader` looks itself up in
`Composer\InstalledVersions` by package name, so it is updated in lockstep
-- installs still on `php4u/module-connector` will read as "not
Composer-managed" (`version()` returns `null`) until upgraded via `composer
require php4u/watchtower-m2-module-connector`. This package was published on
Packagist for less than a day under the old name before this rename;
`php4u/module-connector` should be treated as abandoned there, not as an
alias.

## [1.3.0] - 2026-08-19

Composer package renamed from `watchtower/module-connector` to
`php4u/module-connector` -- the `watchtower` vendor namespace is already in
use by an unrelated party on Packagist, blocking this module from ever being
published there under it. The Magento module identifier
(`Watchtower_Connector`) and PHP namespace (`Watchtower\Connector`) are
unaffected; only the Composer vendor prefix changes. `ConnectorVersionReader`
looks itself up in `Composer\InstalledVersions` by package name, so it is
updated in lockstep -- installs still on the old package name will read as
"not Composer-managed" (`version()` returns `null`) until upgraded via
`composer require php4u/module-connector`.

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
