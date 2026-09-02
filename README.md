# Watchtower Connector for Magento 2

Your checkout broke at 2am. Nobody noticed until a customer emailed at 9.

Uptime monitors do not catch this. The site was up the whole time. It was
returning 200s, serving pages, and quietly failing to take money.

Watchtower Connector watches the things that actually mean your shop is
working: orders completing, carts being created, customers signing in, your ERP
sync still running, cron still ticking, queues still draining. When one of them
stops behaving the way it normally does, you hear about it.

The platform side lives at [watchtower-commerce.com](https://watchtower-commerce.com).
The [documentation](https://watchtower-commerce.com/docs) covers creating a
project, connecting a store, and what each signal means.

## Your numbers never leave your store

This matters enough to be the second thing on the page.

Everything is worked out inside your Magento install. The baseline, the
thresholds, the comparison, the decision that something looks wrong: all of it
runs locally, on your own server.

What gets sent is a word. `NORMAL`, or `SEVERE_DROP`, or `INSUFFICIENT_DATA`,
per signal, per store view. Never an order count, a revenue figure, a customer
name, a product, an email address, or an error message. The build fails if
anything beyond the documented fields reaches the payload, or if the API key
ever turns up in a request body. Those tests live in the source repository, so
you can read them rather than take our word for it.

So you can run this on a store whose numbers you would not share with anyone,
and the answer to "what does Watchtower know about my business" stays "whether
each signal looked normal this hour".

## What it watches

Nine signals. Some compare against your own history, some against fixed
thresholds, some are a straight pass or fail.

**Against your own baseline.** These learn what a normal Tuesday at 3pm looks
like for your shop specifically, then tell you when this Tuesday at 3pm is not
that. A store doing 40 orders a day and a store doing 40,000 each get judged
against themselves.

| Signal | Catches |
|---|---|
| `checkout` | Orders stopped completing |
| `basket_quote` | Nobody is filling carts any more |
| `customer_account` | Sign-ins and registrations fell off a cliff |

**Against fixed thresholds.** No warm-up period. These work on your first day
and on a store of any size, because "half of checkout attempts are failing" is
bad regardless of what normal looks like for you.

| Signal | Catches |
|---|---|
| `checkout_failure` | A payment method, a shipping rate call, or a tax service breaking order placement |
| `admin_auth_failure` | A burst of failed admin sign-ins |

**Pass or fail.** Infrastructure that either works or does not.

| Signal | Catches |
|---|---|
| `cron_health` | Magento's scheduler stopped. Nothing else in Magento works properly when this happens, and stores run for days without noticing |
| `integration_health` | Your ERP, PIM, marketplace or feed sync stopped running |
| `indexer_health` | An indexer stuck invalid, or a materialized-view backlog nothing is draining |
| `queue_health` | Queued work piling up with no consumer attached to it |

`cron_health`, `admin_auth_failure`, `indexer_health` and `queue_health` cover
the whole installation. The rest are reported per store view, so a problem on
your German storefront does not get averaged away by a healthy UK one.

### How fast you hear about it

Signals are evaluated once an hour, on the last complete hour, and a change is
only reported once it has held for two consecutive evaluations. In practice
that means **about one to two hours** from a problem starting to an alert
arriving.

That two-evaluation wait is deliberate. Monitoring you learn to ignore is worse
than none, and a single odd hour is usually just a quiet hour.

### Integrations, without the guesswork

`integration_health` is the one signal you choose the contents of, and it tries
hard not to make that your problem.

The connector reads the scheduled jobs your store actually runs and groups them
under the extension that installed them, so you tick **Mailchimp** rather than
hunting for `ebizmarts_ecommerce` among sixty-odd job codes. Pick as many as
you like.

There is no interval to enter. The connector measures how often each job really
runs on your server and judges it against that. A nightly sync gets judged as a
nightly sync. A job that runs every five minutes gets five minutes. Get that
number wrong by hand and you either miss real outages or cry wolf every night,
which is why the connector works it out instead of asking you.

Integrations that run no cron at all, like an ERP pushing into Magento over the
API, can send a `watchtower_integration_health` event from your own code and
appear in the same list.

## Requirements

- **Magento 2.4.7, 2.4.8 or 2.4.9** (Open Source or Adobe Commerce on-prem)
- **PHP 8.3, 8.4 or 8.5**

Magento's own cron must be running. If it is not, this module cannot do
anything, and `cron_health` is the signal that would have told you.

<details>
<summary>Exact Composer constraints</summary>

```
php                                      ~8.3.0||~8.4.0||~8.5.0
magento/framework                        103.0.*
magento/framework-amqp                   100.4.*
magento/framework-bulk                   101.0.*
magento/framework-message-queue          100.4.*
magento/module-asynchronous-operations   100.4.*
magento/module-backend                   102.0.*
magento/module-config                    101.2.*
magento/module-cron                      100.4.*
magento/module-store                     101.1.*
```

`composer.json` is authoritative; this list is generated from it.
</details>

## Install

```
composer require php4u/module-watchtower-m2-connector
bin/magento module:enable Watchtower_Connector
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:flush
```

Then go to **Stores > Configuration > Watchtower > Connection** and fill in two
fields:

| Field | What to put in it |
|---|---|
| **Watchtower Base URL** | Already filled in with the live platform. Only change it for a self-hosted deployment. |
| **Install API Key** | The key from your Watchtower project. Stored encrypted. |
| **Enabled** | Turns the whole module on and off without losing your settings. |

Click **Test Connection** to check the key before waiting for the next cron
run. No project yet? [Create one and get a key](https://watchtower-commerce.com/docs/connecting-magento-2/add-the-magento-2-connector).
Already have one? [Find or rotate your key](https://watchtower-commerce.com/docs/connecting-magento-2/where-to-find-and-rotate-your-api-key).

Some signals report immediately. `checkout` and `basket_quote` seed their
baseline from your existing order and cart history at install time, so they
usually have something useful to say within a day rather than after weeks of
watching. `customer_account` cannot be seeded this way (there is no historical
log to seed sign-ins and registrations from), so it always starts cold and
needs several weeks of live activity to establish its own baseline, the same
as a brand new store would for any signal.

## Running it

### For devops

**Cron.** One job, `watchtower_report`, is polled every five minutes and does
real work roughly once an hour. It tracks elapsed time since its last real run
rather than watching for a particular minute, so it self-corrects if your host
runs `cron:run` irregularly, and installs naturally spread themselves across
the hour instead of all reporting at once. `watchtower_sync`,
`watchtower_rollup_prune` and `watchtower_event_counter_prune` run daily.
None of it needs configuring.

The module can only run as often as `bin/magento cron:run` is invoked. Magento
recommends every minute; every five minutes is the practical floor here.

**Storefront cost.** Nothing runs in a shopper's request. Observation is cheap
and writes to the module's own tables; all evaluation and network traffic
happens in cron.

**When the platform is unreachable.** Reports are buffered locally and
backfilled on reconnect, with exponential backoff between attempts. An outage
at our end produces a delayed catch-up, not a hole in your history.

**Diagnostics over SSH**, without needing admin access:

```
bin/magento watchtower:status
```

Connection state, buffer backlog, per-signal status and sequence numbers, and
the most recent submission outcomes including anything the platform rejected
and why. The admin **Diagnostics** page shows the same thing.

| Command | What it does |
|---|---|
| `watchtower:ping` | Check connectivity and key validity |
| `watchtower:status` | Full local diagnostics |
| `watchtower:sync` | Push this install's store views now |
| `watchtower:report` | Evaluate and submit now |
| `watchtower:coverage` | Report and seed local baseline history |
| `watchtower:event-counter-prune` | Prune every raw event counter table past retention now |
| `watchtower:rollup-prune` | Roll up and prune aged counters now |

All of these run on a schedule already. The commands exist for setup checks and
troubleshooting.

### For developers

Detection lives in `Model/`, one directory per signal, each with its own
evaluator. The wire contract, including payload shape, evaluation cadence,
debounce rules and the privacy boundary, is specified on the platform side
rather than invented here.

Each signal versions its own `ruleset_version` independently, and reports it on
every submission, so the platform always knows which logic produced a given
status:

| Signal | Constant |
|---|---|
| `cron_health` | `Model/CronHealth/Evaluator::RULESET_VERSION` |
| `checkout`, `basket_quote`, `customer_account` | `Model/RateSignal/DispersionEvaluator::RULESET_VERSION` |
| `checkout_failure` | `Model/CheckoutFailure/Evaluator::RULESET_VERSION` |
| `admin_auth_failure` | `Model/AdminAuthFailure/Evaluator::RULESET_VERSION` |
| `integration_health` | `Model/IntegrationHealth/WatchedSetEvaluator::RULESET_VERSION` |
| `indexer_health` | `Model/IndexerHealth/Evaluator::RULESET_VERSION` |
| `queue_health` | `Model/QueueHealth/Evaluator::RULESET_VERSION` |

There is deliberately no single module-wide version pinned to the spec. Changing
how one signal computes its baseline says nothing about the others, and one
number would hide that.

**Tests.** The unit suite lives in `Test/Unit` in the source repository and
runs on all three supported versions, against the PHPUnit each one ships:
9.6 on 2.4.7, 10.5 on 2.4.8, 12.5 on 2.4.9.

It is `export-ignore`d, so a Composer install gives you the module without the
tests. Clone the repository if you want to run or read them.

**Emitting a custom integration signal** from your own module:

```php
$this->eventManager->dispatch('watchtower_integration_health', [
    'integration' => 'acme_erp',   // appears in the admin once we have seen it
    'status'      => 'ok',          // or 'failed'
    'store_id'    => $storeId,      // optional, defaults to current store
]);
```

Send it once and the label becomes selectable under **Watchtower >
Integrations**. The connector measures how often it arrives and alerts when it
stops. You cannot type a label in by hand, deliberately: a typo would sit there
looking healthy forever while the integration behind it was dead.

## License

[Business Source License 1.1](./LICENSE) (`BUSL-1.1`).

Read it, audit it, run it in production to monitor your own stores. You cannot
use it to build a competing commercial monitoring service. On 2029-08-14 it
converts to MIT. The [`LICENSE`](./LICENSE) file governs.
