# Changelog

All notable changes to this module are documented here. Versioning follows
[Semantic Versioning](https://semver.org/), tracked via git tags on this
repository (`composer.json` deliberately carries no hardcoded `version`
field — Composer's VCS-repository support resolves it from the tag).

## [1.8.1] - 2026-08-21

Fixes invalid XML shipped in v1.7.0/v1.8.0: `etc/crontab.xml`'s comment
for `watchtower_report` contained a literal `--`, which the XML spec
forbids inside a comment body (only the opening `<!--`/closing `-->`
delimiters may contain it). Reworded to avoid it; no other XML file in
the module has the same issue (checked every `<!-- -->` block in every
`.xml` file for a literal `--`, not just this one).

## [1.8.0] - 2026-08-21

`ConnectorVersionReader::version()` now strips the leading "v" this
module's own git tags carry (Composer's `getPrettyVersion()` preserves it
verbatim, e.g. "v1.7.0") before returning it. Left in place, PHP's
`version_compare()` treats an unrecognized leading "v" as ranking BELOW a
normal release -- confirmed directly:
`version_compare('v1.7.0', '1.6.0', '<')` is `true` -- so
`ConnectorVersionCheckService::isBelow()` would have judged every install
"below minimum_version" against the platform's bare-number
`minimum_version`/`latest_version` (Filament-enforced format), regardless
of how current the installed version actually was, and self-disabled it.
Also fixes a cosmetic side effect of the same root cause: both
`bin/magento watchtower:status` and the admin Diagnostics page already
prepend their own literal "v" before displaying this value, which is why
they were showing "vv1.5.0" instead of "v1.5.0".

## [1.7.0] - 2026-08-21

Replaces `Cron\ReportJob`'s per-install "jitter minute" guard with an
elapsed-time one. The old design compared the current wall-clock minute
against a fixed per-install offset (derived from the API key), tolerant of
a few minutes' drift -- correct as long as the host's own system cron
invokes `bin/magento cron:run` at least as often as this job's own
5-minute schedule. On a real production install whose host only ran
`cron:run` every 10 minutes, this install's offset landed on a slot that
was never reached, so the real evaluate-and-submit cycle silently never
ran, permanently -- Magento's own cron_schedule table showed it as
"missed" every single hour, forever, with nothing else anywhere
indicating a problem. `ReportJob` now tracks elapsed time since its last
real run (new singleton-row table, `watchtower_report_cycle_state`)
instead of a wall-clock bucket, so it self-corrects regardless of how
often -- or how irregularly -- the host's own cron actually ticks, as
long as it ticks at all. Also naturally staggers installs across the hour
better than the old hash-based bucketing did, since each one's cycle is
now anchored to whenever it first ran rather than one of only 12 shared
slots.

## [1.6.0] - 2026-08-21

Fixes a real-world first-install bug: saving Base URL, API Key, and Enabled
together in one admin-form submission -- the normal way a merchant sets
the module up for the first time -- silently skipped the on-enable sync
entirely, with no error anywhere. `EnabledSyncTrigger` read those sibling
fields via `ScopeConfigInterface`, which can still hold pre-save (null)
values within the same request; `isConfigured()` then came back false and
the sync was gated out before it ever ran. Fixed by calling
`ReinitableConfigInterface::reinit()` before checking `isConfigured()` --
not a workaround, but the same call `Magento\Config\Model\Config::save()`
itself makes right after its own save transaction commits, for this exact
reason; ours now runs one step earlier, before that method reaches it.
Until the scheduled `watchtower_sync`/`watchtower_report` cron jobs caught
up (once daily / a jittered once-hourly window, respectively), an install
hitting this looked like it was reporting nothing at all.

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
