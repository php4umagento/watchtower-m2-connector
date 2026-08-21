<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Model\StoreView;

/**
 * Advisory-only heuristic for "does this host look local/dev" (PRD FR30).
 * The platform's own blacklist is admin-configurable and not exposed here,
 * so this can never be authoritative -- it just powers a proactive heads-up
 * on the config screen before a merchant ever syncs.
 */
class LocalDomainDetector
{
    /**
     * Checked case-insensitively; each entry matches the bare host or any subdomain of it.
     *
     * @var string[]
     */
    private const LOCAL_SUFFIXES = [
        'localhost',
        'test',
        'example',
        'invalid',
        'local',
        'ddev.site',
        'lndo.site',
        'docksal',
        'docksal.site',
        'vagrant',
        'docker.localhost',
    ];

    /**
     * Whether $url's host looks like a local/development environment rather than a live storefront.
     *
     * @param string $url
     * @return bool
     */
    public function looksLocal(string $url): bool
    {
        // phpcs:ignore Magento2.Functions.DiscouragedFunction.Discouraged -- no Magento-native host parser exists.
        $host = parse_url($url, PHP_URL_HOST);

        if (!is_string($host) || $host === '') {
            return false;
        }

        $host = strtolower($host);

        if (filter_var($host, FILTER_VALIDATE_IP) !== false
            && filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false
        ) {
            return true;
        }

        foreach (self::LOCAL_SUFFIXES as $suffix) {
            if ($host === $suffix || str_ends_with($host, '.' . $suffix)) {
                return true;
            }
        }

        return false;
    }
}
