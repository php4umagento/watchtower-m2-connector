# Integration Health: selection model redesign

Status: design agreed, not yet implemented.
Scope: connector-side only. No change to `docs/connector-metrics-spec.md` in
`watchtower-saas`, and no platform-side work. See "Wire compatibility" for why.

## Why this is being redesigned

The current admin page (`Watchtower > Integration Health Sources`) asks a
merchant to pick, per store view, a source type plus a source identifier plus
an expected max interval in minutes. Five problems, in severity order, all
verified against real installs rather than reasoned from the code.

### 1. The default setting breaks the canonical use case

`Block/Adminhtml/IntegrationHealth/Sources.php` defaults the interval to 60
minutes. Trace a nightly ERP sync through `Model/IntegrationHealth/Evaluator.php`
(`rawStatus()`): a job that succeeded at 02:00 is outside a 60 minute window for
the remaining 23 hours, no failure row exists, so it falls through to
`SevereDrop`. Past the two-evaluation debounce that is a permanent anomaly,
alerting daily, for a perfectly healthy integration.

The merchant gets no warning, and nothing in the UI relates the interval to the
job's real cadence, even though the connector already has it: `getJobs()`
returns `"schedule":"30 2 * * *"` per job.

### 2. The "Queue consumer" source offers an empty list

`Model/IntegrationHealth/AvailableSourcesProvider::queueTopics()` reads
`magento_operation.topic_name`, which is async bulk operations that have already
run, not consumers. Measured:

| Install | `magento_operation` rows | distinct topics | declared consumers |
|---|---|---|---|
| Vanilla 2.4.8 | 0 | 0 | 21 |
| Avalon Guns (production) | n/a | n/a | 0 third-party |

So the option is empty on a fresh install, and on a real production store there
are no third-party consumers to offer anyway. It occupies a third of the picker
and advertises a capability that in practice is not there. This is the same
"a work queue is not a catalogue" trap that `cronJobCodesByGroup()` has a long
comment about having already fixed for cron. The queue side never got the fix.

### 3. The job code does not contain the name the merchant knows

Real integrations on the Avalon Guns store:

| What the merchant calls it | Job codes they must recognize | Declared schedule |
|---|---|---|
| Mailchimp | `ebizmarts_ecommerce`, `ebizmarts_webhooks`, `ebizmarts_clean_webhooks`, `ebizmarts_clean_batches` | */5, */5, hourly, hourly |
| M2E Pro (Amazon/eBay) | `ess_m2epro` | every minute |
| Bespoke stock feed | `avalon_conditions` (+2 sibling modules) | */15 |

The Composer package is `mailchimp/mc-magento2` and the job codes say
`ebizmarts`. A merchant scanning 64 entries for "mailchimp" finds nothing. There
is no path from what they know to what they must select.

### 4. One integration is many jobs, and picking the wrong one is silent

Mailchimp is four jobs on two cadences. `Model/IntegrationHealth/IntegrationHealthConfig.php`
allows exactly one source per store view. Pick `ebizmarts_clean_batches` and you
are monitoring a housekeeping task: it stays green forever while the actual
ecommerce sync is dead. Nothing signals that you picked the useless one.

### 5. No feedback after saving

The page shows no current status, no last observed success or failure, and no
"we have never seen this source". For a config screen whose entire failure mode
is silently watching the wrong thing, that is the biggest usability miss. The
evidence already exists in `watchtower_integration_health_state`, refreshed every
cron tick by `ReportingService::snapshotIntegrationHealthEvidence()`.

Secondary: per-row Save with no page-level save, three stacked identifier
controls toggled by JS in one cell, a dummy `<form onsubmit="return false">`
that exists only to make jQuery validation fire, and implementation vocabulary
throughout ("Source Type", "Source Identifier", "Convention event").

## The redesign

The unit of selection stops being "a source type plus an identifier" and becomes
**the integration**, auto-discovered and named the way the merchant names it.

### Decisions

| Decision | Choice | Why |
|---|---|---|
| Selection unit | Auto-discovered integrations, grouped by vendor/module | The merchant thinks "Mailchimp", not `ebizmarts_ecommerce`. Module attribution is derivable, needs no curated knowledge base, and works on any store. |
| Threshold | Derived from **observed** cadence, never typed by the merchant | Declared schedule is what the job should do; observed is what it actually does. On a congested store a `*/5` job may really run every 18 minutes, and a declared-schedule threshold would alert constantly. Self-calibrates per store. |
| Cold start | Offer everything immediately; report `INSUFFICIENT_DATA` until cadence is learned | A strict "only show what we have observed" list is empty right after install, and a nightly job takes days to appear, at exactly the moment the merchant is setting things up. `INSUFFICIENT_DATA` and the `observingSince` grace already exist in `Evaluator`. |
| Erratic jobs | Listed with a warning, still selectable | A merchant whose one bespoke integration runs irregularly still needs a path. Judgement stays visible rather than hidden. |
| Naming | Group by vendor, annotate each job with observed cadence | Solves the `ebizmarts`-is-Mailchimp recognition problem, which regularity alone does not touch. |
| Queue consumers | Folded into the vendor entry, not a separate source type | If a watched module declares consumers, they are watched under the same integration. The empty `magento_operation` dropdown disappears rather than being rebuilt. |
| Wire mapping | Worst-of rollup to one status per store view | No spec change, no platform work, no billing change. Matches the split `queue_health` already chose for queue identity. |

### Why regularity and module attribution are both needed

They answer different questions and neither substitutes for the other:

- **Module attribution** answers "is this an integration, and whose?" It excludes
  Magento core and gives the merchant a recognizable name.
- **Observed regularity** answers "is this monitorable, and at what threshold?"
  It excludes erratic jobs and sets the interval with no merchant arithmetic.

Regularity alone is insufficient because core housekeeping is perfectly regular:
`visitor_clean`, `backend_clean_cache` and `security_clean_admin_expired_sessions`
would all pass a regularity filter, and so would `ebizmarts_clean_batches`.

## Data model

### New: `watchtower_cron_job_observation`

There is no existing storage for per-job run history.
`watchtower_integration_health_state` holds only the configured source's last
success/failure, and `CronHealth/CronScheduleObserver` snapshots install-wide
freshest-only with no per-job breakdown.

| Column | Type | Notes |
|---|---|---|
| `job_code` | varchar(255), PK | |
| `first_observed_at` | datetime | Drives the "learning cadence" state |
| `last_success_at` | datetime, null | |
| `observed_run_count` | int | |
| `gap_samples` | text, null | JSON array of the last N inter-run gaps in seconds, N = 20 |
| `median_gap_seconds` | int, null | Denormalised from `gap_samples` for cheap reads |
| `updated_at` | datetime | |

Cardinality is roughly the number of declared jobs (68 on vanilla 2.4.8), so
this stays a small table.

**Collection**: extend the existing every-tick snapshot. `watchtower_report`
runs `*/5`, comfortably inside Magento's 60 minute `history_success_lifetime`
default, so no success is missed.

One correctness detail worth stating because it is easy to get wrong: the
snapshot must record **all** successes since the previous tick, not just the
freshest. Recording only the freshest makes a per-minute job such as
`ess_m2epro` measure as "every 5 minutes", the tick period rather than the job
period.

### Cadence estimation

- **Confident** when `observed_run_count >= MIN_SAMPLES` and the gap
  distribution is tight enough (proposed: p90 gap <= 2x median gap).
- **Threshold** derived from the observed distribution, not the median alone, so
  normal cron jitter does not trip it. Proposed: `max(p95_gap * 1.5, median * 2)`.
- **Floor** the threshold. The platform evaluates hourly with a two-evaluation
  debounce, so real alert latency is one to two hours regardless. A threshold
  tighter than that buys no detection speed and only adds false positives.

The exact constants above need calibration against real cron data before they
are fixed. They are proposals, not settled values. Confirm the connector-side
evaluation cadence in `ReportingService` while calibrating, since it determines
the useful resolution.

### Discovery

1. Enumerate declared jobs via `Cron\ConfigInterface::getJobs()` (already done).
2. Exclude `watchtower_*` (already done, and already enforced server-side in
   `IntegrationHealthConfigValidator` against a direct POST).
3. Attribute each job to a module from the `instance` FQCN, then resolve the
   Composer package name via `ComponentRegistrar` for the display label. This is
   what turns `ebizmarts_ecommerce` into "Mailchimp".
4. Classify Magento core versus third-party, and rank third-party first.
5. Merge declared consumers per module via `MessageQueue\Consumer\ConfigInterface`,
   the same interface `QueueHealth/QueueStateObserver` already uses.
6. Keep the existing union with `cron_schedule` so programmatically-inserted
   jobs that appear in no `crontab.xml` are still offered.

## UI

Vendor-grouped checklist replacing the per-store-view table. Proposed shape:

```
Which integrations should we watch?

  [x] Mailchimp                mailchimp/mc-magento2
      ebizmarts_ecommerce      every 5 min (observed, 240 runs)   OK, last run 2 min ago
      ebizmarts_webhooks       every 5 min (observed, 240 runs)   OK, last run 4 min ago

  [x] M2E Pro                  m2epro/magento2-extension
      ess_m2epro               every minute (observed, 1400 runs) OK, last run 1 min ago

  [ ] Avalon Feed              Avalon_ConditionsCron
      avalon_conditions        learning cadence, live in about 2h

  [ ] Salesfire                salesfire/magento2
      salesfire_sync           runs irregularly, alerting may be unreliable
```

Requirements this encodes:

- No interval field anywhere.
- Live status per entry, read from existing state plus the new observation
  table. This closes problem 5 directly.
- Erratic entries carry a visible warning but remain selectable.
- Advanced disclosure to drill into individual job codes for merchants who want
  `ebizmarts_ecommerce` specifically rather than all of Mailchimp's jobs.
- A page-level Save, replacing per-row saves and the dummy validation form.
- Merchant-facing wording throughout. Rename the menu item away from
  "Integration Health Sources".

Note for whoever writes the copy: no em dashes in any merchant-facing string.

### Known limitation

Watching all of a module's jobs worst-of means a failing housekeeping job raises
an integration alert. Which of Mailchimp's four jobs is the meaningful one is
not derivable, so the advanced drill-down is the escape hatch. Do not pretend to
solve this heuristically.

## Wire compatibility

`docs/connector-metrics-spec.md:539` requires `store_view_code` on
`integration_health` and allows one status per store view. The watched set is
install-level, because cron jobs and queue consumers have no store dimension.

Therefore:

- The connector collapses every watched integration into one **worst-of** status
  and reports it for each live store view.
- Convention-event integrations stay store-view-scoped and roll into that store
  view's status only.
- The failing integration is **not** named on the wire. It must be surfaced
  locally in the admin page and in `bin/magento watchtower:status`, which is the
  same split `queue_health` chose deliberately for queue identity (spec 2.12
  leak review). Consistent with house design rather than a new compromise.

Nothing above changes a field, an enum value, a required-ness rule or a
rejection semantic, so no spec version bump and no platform change.

Rejected alternatives, recorded so they are not relitigated by accident:

- **Per-integration signals with an added wire identifier.** Better alerting,
  since the platform could say "Mailchimp is down". Rejected for now: spec bump,
  ingestion and alerting changes, and it multiplies signal count per install,
  which has billing implications that need thinking through separately.
- **Making `integration_health` install-scoped** like `cron_health`,
  `indexer_health` and `queue_health`. Removes the configure-the-same-job-twelve-times
  problem at the root, but needs a spec change and drops the genuinely
  per-store-view case of a different ERP per country storefront.

## Shipped: convention events and the retirement

Both sections that used to sit here are done, and the detail worth keeping is
recorded where it belongs rather than in a plan.

**Convention events** (1.25.0 backend, 1.26.0 picker). Two things settled by
reading the code rather than reasoning. `watchtower_integration_health_event`
stores one row per dispatch, so the gaps between them feed the same
`CadenceEstimator` the cron side uses and events need no typed interval either.
And there was no state-row conflict: `WatchedSetEvaluator::evaluate()` already
received the store view id, so labels fold into the same worst-of and the same
debounce.

The picker offers **only labels already seen dispatched**, never free text. An
earlier draft of this plan proposed a text field with suggestions; that was
rejected because a mistyped label sits in the watched set forever matching
nothing, reporting healthy while the integration it covered is dead. The
accepted cost is that nothing can be selected until the merchant's module has
dispatched once.

**The retirement** (1.27.0). One behaviour had to be carried across rather than
deleted with the rest: the old path kept a store view whose source had been
cleared heartbeating its last known status, because the platform's staleness
sweep has no concept of a deliberately retired signal and silence would alert
forever. Emptying the watched set is the same situation, so
`heartbeatRetiredIfPreviouslyReported()` moved to `WatchedSetEvaluator`.
Deleting the fallback without it would have turned unticking everything into a
permanent false alert.

`queue_consumer` stays retired. The evidence against it is unchanged: zero
`magento_operation` rows on vanilla 2.4.8, no third-party consumers on the
production store checked.

## Still open: calibrate the cadence constants

`MIN_GAP_SAMPLES`, the p95 and median multipliers and the one-hour floor in
`CadenceEstimator` all shipped as **proposals**. The floor's reasoning is
sound, since the platform evaluates hourly with a two-evaluation debounce, but
none of the numbers has been checked against a spread of real integrations.

Cadence never crosses the wire, so the platform cannot see it: calibration has
to happen on an install. The local test installs are near-vanilla and would
only show core housekeeping on tidy schedules, which is the easy case. Avalon
Guns is the intended source, being the store whose data already exposed three
wrong assumptions in this redesign.

This is the only outstanding item that can mislead a merchant rather than just
leave tidy-up behind.

## Migration

Existing `watchtower_integration_health_config` rows must not silently stop
working:

- `cron_job` rows: carry over as an explicitly watched job under its module.
- `convention_event` rows: carry over unchanged, still store-view-scoped.
- `queue_consumer` rows: map to the declaring module's consumer where
  resolvable; otherwise drop with an admin notice rather than failing silently.

`Evaluator::heartbeatRetiredIfPreviouslyReported()` already handles a source
going away without tripping the platform's staleness sweep. Reuse it rather
than inventing a second retirement path.

## Testing

Beyond the usual unit coverage:

- A nightly job with a learned daily cadence reports `NORMAL`, not `SevereDrop`.
  This is problem 1 and it deserves a named regression test.
- A per-minute job measures as per-minute, not as the 5 minute tick period.
- A job with no observation history is offered, and reports `INSUFFICIENT_DATA`
  rather than an anomaly.
- Worst-of rollup: one failing integration among several drives the store view
  status, and the failing one is identifiable in `watchtower:status`.
- Migration: each of the three legacy source types lands correctly, including
  the unresolvable `queue_consumer` case.
- `ObserverSafetyTest` still passes for any new observer.

## Delivery order

1. **Observation table plus collection.** No UI change. Starts accumulating
   cadence data immediately, which every later stage depends on and which needs
   real elapsed time to become useful. **Shipped.**
2. **Discovery and attribution.** Vendor names and cadence annotations. Fixes
   problems 2 and 3 without touching the data model. **Shipped.**
3. **Derived thresholds.** Fixes problem 1. **Shipped.**
4. **Multi-select plus worst-of rollup, with migration.** Fixes problem 4.
5. **Page rebuild with live status.** Fixes problem 5 and the secondary UI debt.

Stage 1 first is deliberate: cadence data has to age before stages 3 and 4 can be
validated against anything real.

### Correction: stages 4 and 5 cannot be split

This section originally claimed all five stages were independently shippable.
That is true of 1 to 3 and false of 4 and 5, which have to land together.

Stage 4 changes what the evaluator **reads**, from the per-store-view config
row to the install-level watched set. Stage 5 changes what the admin page
**writes**. Ship 4 alone and the existing page writes a table nothing reads;
ship 5 alone and the new page writes a table nothing reads. Either half on its
own is a silently broken configuration screen, which is the exact failure mode
this redesign exists to remove.

Stage 3 left one deliberate transitional inconsistency that stage 5 closes: the
expected-max-interval field is still rendered but is no longer consulted for
cron jobs. It still is for the other two source types, so it cannot simply be
deleted ahead of the rebuild.

### Simplification found while implementing stage 4

The original sketch implied one debounce state machine per watched integration,
rolled up afterwards. That is unnecessary. `watchtower_cron_job_observation`
already carries each job's `last_success_at` durably, past Magento's hourly
purge, so per-job evidence needs no per-job state of its own.

The rollup is therefore: compute each watched job's raw status from its own
observation and its own derived threshold, take the worst, and feed that single
value into the **existing** per-store-view two-evaluation debounce, fingerprinted
on the watched set so that changing the set re-seeds rather than carrying a
verdict about different jobs.

One thing that must not be lost in doing so: the observation table records
successes only, so failure evidence (`STATUS_ERROR`, `STATUS_MISSED`) still has
to come from `CronJobObserver` per watched job, or a job that runs and fails
every time would be judged healthy on cadence alone.
