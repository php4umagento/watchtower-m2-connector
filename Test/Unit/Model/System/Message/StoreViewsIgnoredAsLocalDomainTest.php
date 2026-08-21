<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Test\Unit\Model\System\Message;

use Magento\Framework\Escaper;
use Magento\Framework\Notification\MessageInterface;
use PHPUnit\Framework\TestCase;
use Watchtower\Connector\Model\StoreView\IgnoredDomainState;
use Watchtower\Connector\Model\StoreView\IgnoredDomainStateRepository;
use Watchtower\Connector\Model\System\Message\StoreViewsIgnoredAsLocalDomain;

class StoreViewsIgnoredAsLocalDomainTest extends TestCase
{
    public function testIsNotDisplayedWhenTheLastSyncIgnoredNothing(): void
    {
        $message = $this->message($this->state(ignoredCount: 0));

        self::assertFalse($message->isDisplayed());
    }

    public function testIsNotDisplayedWhenNeverSynced(): void
    {
        $message = $this->message(new IgnoredDomainState(ignoredCount: 0, exampleCode: null, occurredAt: null));

        self::assertFalse($message->isDisplayed());
    }

    public function testIsDisplayedWhenTheLastSyncIgnoredAtLeastOneStoreView(): void
    {
        $message = $this->message($this->state(ignoredCount: 2));

        self::assertTrue($message->isDisplayed());
    }

    public function testTheTextNamesTheCountAndAnExampleStoreView(): void
    {
        $text = (string) $this->message($this->state(ignoredCount: 3))->getText();

        self::assertStringContainsString('3', $text);
        self::assertStringContainsString('default', $text);
    }

    /**
     * The example store view code originates from an HTTP response body, so
     * it is an attacker-influenced string rendered into the admin's own
     * HTML -- it must go through Escaper rather than being interpolated raw.
     */
    public function testTheExampleCodeIsEscapedBeforeReachingTheAdminHtml(): void
    {
        $escaper = $this->createMock(Escaper::class);
        $escaper->expects(self::once())->method('escapeHtml')->willReturnCallback(
            static fn (string $value): string => 'escaped(' . $value . ')'
        );
        $escaper->method('escapeUrl')->willReturnArgument(0);

        $text = (string) $this->message($this->state(ignoredCount: 1), $escaper)->getText();

        self::assertStringContainsString('escaped(default)', $text);
    }

    /**
     * Magento persists per-identity dismissal state, so the identity is a
     * stored key rather than an implementation detail -- changing it would
     * resurrect a notice an admin already dealt with.
     */
    public function testTheIdentityIsStable(): void
    {
        self::assertSame(
            'watchtower_store_views_ignored_as_local_domain',
            $this->message($this->state(ignoredCount: 1))->getIdentity()
        );
    }

    /**
     * An ignored local/dev domain is expected and harmless -- informational,
     * not something broken that needs fixing, unlike the MAJOR-severity
     * below-minimum-version notice.
     */
    public function testTheSeverityIsNotice(): void
    {
        self::assertSame(
            MessageInterface::SEVERITY_NOTICE,
            $this->message($this->state(ignoredCount: 1))->getSeverity()
        );
    }

    private function state(int $ignoredCount): IgnoredDomainState
    {
        return new IgnoredDomainState(
            ignoredCount: $ignoredCount,
            exampleCode: 'default',
            occurredAt: new \DateTimeImmutable('2026-08-14T10:00:00+00:00'),
        );
    }

    private function message(IgnoredDomainState $state, ?Escaper $escaper = null): StoreViewsIgnoredAsLocalDomain
    {
        $repository = $this->createStub(IgnoredDomainStateRepository::class);
        $repository->method('get')->willReturn($state);

        if ($escaper === null) {
            $escaper = $this->createStub(Escaper::class);
            $escaper->method('escapeHtml')->willReturnArgument(0);
            $escaper->method('escapeUrl')->willReturnArgument(0);
        }

        return new StoreViewsIgnoredAsLocalDomain($repository, $escaper);
    }
}
