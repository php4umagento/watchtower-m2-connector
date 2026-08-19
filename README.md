# Watchtower Connector for Magento 2

Watchtower Connector monitors a Magento 2 store and reports coarse anomaly
statuses (normal / mild drop / severe drop / mild spike / severe spike /
insufficient data) to the Watchtower platform, so a merchant learns about a
broken checkout, a stalled integration, or a silent cron scheduler before a
customer complains.

## How it works

All detection runs **locally, inside your store**: baseline computation,
thresholds, debounce, and anomaly bucketing never leave the connector. The
platform never receives raw order counts, customer data, or any other
business metric — only a coarse status enum per tracked signal, plus
metadata used for staleness detection and deduplication (sequence numbers,
timestamps).

Tracked signals:

- **`cron_health`** — is Magento's own cron scheduler still running? Ships
  even on the platform's free tier; requires no store traffic to be useful.
- **`checkout`**, **`basket_quote`**, **`customer_account`** — rate-based
  signals comparing this hour's activity against your store's own historical
  baseline for the same hour of day.
- **`integration_health`** — an optional, per-store-view signal for a
  third-party integration (a cron job, a queue consumer, or a custom
  convention event you emit) — configured per store view in the admin.

## Requirements

- PHP `~8.3.0 || ~8.4.0 || ~8.5.0`
- `magento/framework` `103.0.*`
- `magento/framework-bulk` `101.0.*`
- `magento/module-store` `101.1.*`
- `magento/module-backend` `102.0.*`
- `magento/module-config` `101.2.*`
- `magento/module-asynchronous-operations` `100.4.*`

(See `composer.json` for the authoritative, currently-enforced version
constraints — the list above mirrors it and may drift if you're reading an
old copy of this file.)

## Installation

```
composer require php4u/module-connector
bin/magento module:enable Watchtower_Connector
bin/magento setup:upgrade
```

A full `setup:di:compile` and cache flush is recommended after enabling, as
with any new module:

```
bin/magento setup:di:compile
bin/magento cache:flush
```

## Configuration

Go to **Stores > Configuration > Watchtower > Connection**:

| Field | What it's for |
|---|---|
| **Watchtower Base URL** | The base URL of your Watchtower platform instance. Pre-filled with the live Watchtower platform; only change this for a custom deployment. |
| **Install API Key** | The install-scoped API key generated for this install on the Watchtower projects page. Stored encrypted. |
| **Enabled** | Master on/off switch. Disabling stops the connector from syncing, evaluating, or submitting anything — without discarding your saved configuration. |

After saving, click **Test Connection** on the same page to confirm the URL
and key are valid before waiting for the next scheduled cron cycle. The same
check is available from the command line:

```
bin/magento watchtower:ping
```

## CLI commands

| Command | What it does |
|---|---|
| `watchtower:ping` | Checks connectivity to the configured Watchtower platform. |
| `watchtower:sync` | Reports this install's live store views to Watchtower now. |
| `watchtower:report` | Evaluates tracked signals and submits any due reports to Watchtower now. |
| `watchtower:status` | Prints diagnostics: connection state, buffer backlog, and per-signal status — the same data the admin Diagnostics page shows, headlessly. Useful for support to run over SSH. |
| `watchtower:coverage` | Seeds and reports local historical baseline coverage for every live store view. |
| `watchtower:rollup-prune` | Rolls aged hourly counters into daily rollups and prunes both tables to their retention window now. |

All of these also run automatically on a schedule (see below); the CLI forms
exist for on-demand checks and troubleshooting.

## Admin pages

Under the **Watchtower** menu:

- **Integration Health Sources** — per-store-view picker for the
  `integration_health` signal's source: a cron job, a queue consumer/bulk
  operation, or a custom convention event. A store view left unconfigured
  simply isn't evaluated for this signal — every other signal keeps working
  regardless.
- **Diagnostics** — connection state, last successful submission, buffered
  report backlog, dropped-event count, every live store view's per-signal
  status and sequence number, and the most recent submission outcomes
  (accepted/rejected, with reasons). The same data `watchtower:status`
  prints on the command line.

## Cron schedule

`watchtower_report` runs every 5 minutes by design, but only actually
evaluates and submits once per hour — the connector jitters its real
schedule to a per-install offset (derived from your own API key) within that
hour, so every installation of this module doesn't submit at the exact same
wall-clock minute. `watchtower_sync` and `watchtower_rollup_prune` both run
once daily. None of this requires configuration; it's automatic once
Magento's own cron is running.

## License

This module ships under the [Business Source License 1.1](./LICENSE)
(SPDX: `BUSL-1.1`). You can read, audit, and run it to monitor your own
Magento store(s), including in production; you can't use it, in whole or
in part, to offer a competing commercial monitoring or alerting service.
On 2029-08-14 it automatically converts to the MIT License. See
[`LICENSE`](./LICENSE) for the full, governing terms.

## Compatibility

The wire protocol this connector speaks against the Watchtower platform is
currently at **spec version 2.4**.

There is deliberately no single "module version pinned to the spec version"
number. Each tracked signal's evaluator versions its own `ruleset_version`
independently, since a change to one signal's baseline logic (e.g. the
dispersion-based bound `checkout`/`basket_quote`/`customer_account` use)
doesn't necessarily mean every other signal's detection logic changed too:

- `cron_health` — `Model/CronHealth/Evaluator::RULESET_VERSION`
- `checkout` / `basket_quote` / `customer_account` — `Model/RateSignal/DispersionEvaluator::RULESET_VERSION`
- `integration_health` — `Model/IntegrationHealth/Evaluator::RULESET_VERSION`

Each is reported per-signal on every submitted report, so the platform
always knows exactly which baseline logic produced a given status — a
single module-wide version number would only obscure that.
