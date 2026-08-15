<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Test\Unit;

use Magento\Store\Model\Group;
use Magento\Store\Model\Store;
use Magento\Store\Model\Website;

/**
 * Shared store-stub builder for tests that need a Store with a working
 * Website/Group hierarchy; used by anything exercising
 * StoreViewSyncService, since that's the shape it reads.
 */
trait StoreStubTrait
{
    private function activeStore(string $code = 'default'): Store
    {
        return $this->buildStore($code, true);
    }

    private function inactiveStore(string $code): Store
    {
        return $this->buildStore($code, false);
    }

    private function buildStore(string $code, bool $isActive): Store
    {
        $website = $this->createStub(Website::class);
        $website->method('getName')->willReturn('Main Website');

        $group = $this->createStub(Group::class);
        $group->method('getName')->willReturn('Main Website Store');

        $store = $this->createStub(Store::class);
        $store->method('getCode')->willReturn($code);
        $store->method('getName')->willReturn('Default Store View');
        $store->method('getBaseUrl')->willReturn('https://m2.test/');
        $store->method('getIsActive')->willReturn($isActive);
        $store->method('getWebsite')->willReturn($website);
        $store->method('getGroup')->willReturn($group);
        $store->method('getId')->willReturn(1);

        return $store;
    }
}
