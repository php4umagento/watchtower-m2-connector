<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Model\IndexerHealth;

use Magento\Framework\App\ResourceConnection;

/**
 * Polls indexer_state and mview_state for the indexer_health signal: the raw
 * material Evaluator turns into a status.
 *
 * Reports WHEN an unhealthy condition started, never what or how much. The
 * identity of an affected indexer and the size of a changelog backlog both
 * stay inside the store, because both are disclosive: which indexer is
 * churning implies which parts of the catalogue are being worked on, and a
 * backlog row count is a proxy for catalogue-change volume. See the leak
 * review in the platform's connector-metrics-spec.md, "Worked Example: Adding
 * indexer_health".
 *
 * Three conditions count as unhealthy, and all three are read from `updated`,
 * which for these tables records when the row's CURRENT status began:
 *
 * 1. An indexer sitting `invalid` or `working`. Both are entirely normal for
 *    minutes after an import, so it is Evaluator that applies the duration
 *    window; this class only reports the onset.
 * 2. An enabled materialized view sitting `working`, i.e. it started
 *    processing a batch and has not finished.
 * 3. An enabled, idle view whose version_id trails its changelog head. Idle
 *    means nothing is draining it, so unlike (2) this is a backlog nobody is
 *    working on.
 *
 * A `valid` indexer's `updated` is deliberately ignored: it records when the
 * last reindex FINISHED, which is routinely days old on a healthy store, so
 * treating its age as staleness would report every quiet catalogue as broken.
 */
class IndexerStateObserver
{
    /**
     * Statuses that mean an indexer is not currently usable as up to date.
     * Magento writes these as plain strings rather than exposing a shared
     * constant on a class this module can depend on without pulling in the
     * indexer framework, so they are matched literally.
     */
    private const INDEXER_UNHEALTHY_STATUSES = ['invalid', 'working'];

    /**
     * @param ResourceConnection $resourceConnection
     */
    public function __construct(
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    /**
     * Polls both state tables and reports the oldest unhealthy onset found.
     *
     * @param \DateTimeImmutable $now
     * @return Observation
     */
    public function observe(\DateTimeImmutable $now): Observation
    {
        $onsets = array_merge($this->indexerOnsets(), $this->mviewOnsets());

        return new Observation(
            unhealthySince: $onsets === [] ? null : min($onsets),
            suspended: $this->hasSuspendedView(),
        );
    }

    /**
     * Onset timestamps for every indexer currently invalid or mid-rebuild.
     *
     * @return \DateTimeImmutable[]
     */
    private function indexerOnsets(): array
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('indexer_state');

        $rows = $connection->fetchCol(
            $connection->select()
                ->from($table, ['updated'])
                ->where('status IN (?)', self::INDEXER_UNHEALTHY_STATUSES)
                ->where('updated IS NOT NULL')
        );

        return $this->toDates($rows);
    }

    /**
     * Onset timestamps for unhealthy enabled materialized views.
     *
     * Either stuck mid-batch, or sitting on a backlog nobody is draining.
     *
     * @return \DateTimeImmutable[]
     */
    private function mviewOnsets(): array
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('mview_state');

        $views = $connection->fetchAll(
            $connection->select()
                ->from($table, ['view_id', 'status', 'updated', 'version_id'])
                ->where('mode = ?', 'enabled')
                ->where('updated IS NOT NULL')
        );

        $onsets = [];

        foreach ($views as $view) {
            if ($view['status'] === 'working') {
                $onsets[] = $view['updated'];

                continue;
            }

            if ($view['status'] === 'idle' && $this->isBehindChangelog($view['view_id'], $view['version_id'])) {
                $onsets[] = $view['updated'];
            }
        }

        return $this->toDates($onsets);
    }

    /**
     * Whether a view trails the newest entry in its own changelog table.
     *
     * Returns false when the changelog table is absent rather than treating
     * that as a backlog: Magento creates `{view_id}_cl` when the view is
     * subscribed, so a missing table means this view has nothing to fall
     * behind, not that it is infinitely behind.
     *
     * Only MAX() is read, never a row, so this stays inside the same
     * aggregate-only constraint every other signal source is held to, and the
     * number itself never leaves this method.
     *
     * @param string $viewId
     * @param string|int|null $versionId
     * @return bool
     */
    private function isBehindChangelog(string $viewId, string|int|null $versionId): bool
    {
        if ($versionId === null) {
            return false;
        }

        $connection = $this->resourceConnection->getConnection();
        $changelog = $this->resourceConnection->getTableName($viewId.'_cl');

        if (!$connection->isTableExists($changelog)) {
            return false;
        }

        $head = $connection->fetchOne($connection->select()->from($changelog, ['MAX(version_id)']));

        // MAX() over an empty changelog yields null rather than a row, which
        // casts to 0 and correctly reads as "nothing to be behind on". Cast
        // rather than compared against false/null separately: fetchOne()'s
        // declared return type makes one of those checks provably dead.
        return (int) $head > (int) $versionId;
    }

    /**
     * Whether any materialized view is suspended.
     *
     * Not folded into mviewOnsets(): a suspended view is wrong immediately
     * rather than after a window, so Evaluator needs it as its own fact and
     * not as another onset timestamp to age.
     *
     * @return bool
     */
    private function hasSuspendedView(): bool
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('mview_state');

        return (bool) $connection->fetchOne(
            $connection->select()
                ->from($table, ['view_id'])
                ->where('status = ?', 'suspended')
                ->limit(1)
        );
    }

    /**
     * Parses Magento's datetime strings into UTC dates, skipping unparseable ones.
     *
     * @param string[] $values
     * @return \DateTimeImmutable[]
     */
    private function toDates(array $values): array
    {
        $dates = [];

        foreach ($values as $value) {
            $date = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string) $value, new \DateTimeZone('UTC'));

            if ($date !== false) {
                $dates[] = $date;
            }
        }

        return $dates;
    }
}
