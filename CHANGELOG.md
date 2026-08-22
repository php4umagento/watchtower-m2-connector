# Changelog

All notable changes to this module are documented here. Versioning follows
[Semantic Versioning](https://semver.org/), tracked via git tags on this
repository (`composer.json` deliberately carries no hardcoded `version`
field — Composer's VCS-repository support resolves it from the tag).

## [1.16.0] - 2026-08-22

The admin diagnostics page and `watchtower:status` now show which named
checks (dispersion, seasonal, trend) actually drove a rate-based signal's
last classification, for a status transition produced by the ensemble
combiner. Adds an `ensemble_driving_checks` column to
`watchtower_dispersion_state`; run `setup:upgrade`.

## [1.15.0] - 2026-08-22

The admin diagnostics page and `watchtower:status` now show the reason
(heartbeat or transition) behind every signal's last reported status, not
just the status itself. Adds a `last_reported_reason` column to all three
signal state tables (`watchtower_health_state`, `watchtower_dispersion_state`,
`watchtower_integration_health_state`); run `setup:upgrade`.

## [1.14.0] - 2026-08-22

The admin diagnostics page and `watchtower:status` now show a store's
estimated detection latency for a full outage on any signal currently in
Low-Volume Signal Mode, so a low-volume merchant sees an honest confidence
number instead of a silent status toggle.

## [1.13.0] - 2026-08-22

Low-Volume Signal Mode now reports `INSUFFICIENT_DATA` instead of a
percentile-based verdict when a signal's estimated daily volume is below 5
orders/day, the floor the spec's own simulation actually validated. A
near-dormant store view with only a handful of historical events could
otherwise report `SEVERE_DROP` off a gap distribution too thin to mean
anything, the moment its already-typical silence passed the small sample's
own maximum. `ruleset_version` bumps to 1.4.0.

## [1.12.0] - 2026-08-22

Seeds a store view's historical baseline automatically the first time it's
evaluated, instead of only via the manual `watchtower:coverage` command --
an install that never had it run by hand previously cold-started on
whatever the live cycle collected from scratch, risking a false anomaly
off a few hours of noise instead of a real baseline.

## [1.11.0] - 2026-08-22

Fixes a false "back to normal" email on a brand-new install or store view:
`CronHealth`, `IntegrationHealth`, and `RateSignal\DispersionEvaluator` now
report `NORMAL` confirmed straight out of the `INSUFFICIENT_DATA` seed as a
heartbeat instead of a transition, since nothing was ever actually down.

## [1.10.1] - 2026-08-21

Condenses this changelog's entries. No functional change.

## [1.10.0] - 2026-08-21

Prunes `watchtower_event_counter` and `watchtower_event_drop_counter`,
which previously grew unbounded (90-day retention, new
`watchtower:event-counter-prune` command). Also moves all
`watchtower_*` cron jobs into their own dedicated cron group, isolated
from Magento's shared `default` group.

## [1.9.0] - 2026-08-21

Adds a Configuration link under the Watchtower admin menu, and the
FR27 admin notice for available connector updates. Also fixes invalid
`db_schema.xml` comments (apostrophes/backslashes) that broke
`setup:upgrade`.

## [1.8.1] - 2026-08-21

Fixes invalid XML in `etc/crontab.xml` (a stray `--` inside a comment)
shipped in v1.7.0/v1.8.0.

## [1.8.0] - 2026-08-21

Fixes the reported connector version showing a leading "v", which also
threw off the platform's version comparison.

## [1.7.0] - 2026-08-21

Replaces `ReportJob`'s wall-clock jitter with an elapsed-time guard.
Fixes installs whose host cron ran less often than every 5 minutes
silently never reporting.

## [1.6.0] - 2026-08-21

Fixes on-enable sync silently no-op'ing when Base URL, API Key, and
Enabled were all saved together on first setup.

## [1.5.0] - 2026-08-21

Surfaces ignored local/dev-domain syncs to the merchant via an admin
notice instead of only logging them. Also warns on the config screen
if the current store's own URL looks local.

## [1.4.1] - 2026-08-19

Renames the Composer package to `php4u/module-watchtower-m2-connector`
— the previous name broke Magento's own unit test suite discovery.

## [1.4.0] - 2026-08-19

Renames the Composer package to `php4u/watchtower-m2-module-connector`
(naming preference).

## [1.3.0] - 2026-08-19

Renames the Composer package to `php4u/module-connector` — the
`watchtower` vendor namespace was already taken on Packagist.

## [1.2.0] - 2026-08-16

Adds a minimum/latest version check against the platform. Installs
below the minimum self-disable sync and reporting (buffering
continues) until upgraded; below latest is advisory only. Replaces the
old GitHub-polling update check — run `bin/magento setup:upgrade`
after updating, it drops two now-unused columns.

## [1.1.0] - 2026-08-16

Syncs now report this install's Magento version/edition and the
connector's own version. The platform echoes back EOL and
update-availability status in response.

## [1.0.1] - 2026-08-15

Added a copyright/license header to every source file. No behavioral
change.

## [1.0.0] - 2026-08-15

Initial release.
