<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Test\Unit\Model\IntegrationHealth;

use Magento\Framework\Component\ComponentRegistrarInterface;
use Magento\Framework\Filesystem\Driver\File;
use Magento\Framework\ObjectManager\ConfigInterface as ObjectManagerConfigInterface;
use PHPUnit\Framework\TestCase;
use Watchtower\Connector\Model\IntegrationHealth\ModuleAttribution;

/**
 * The two attribution failures covered here were both found against a real
 * 2.4.9 install rather than reasoned about, and between them accounted for
 * every unattributed job on it: eight stock sales jobs that name DI virtual
 * types, and one that names a framework class.
 */
class ModuleAttributionTest extends TestCase
{
    private const MODULE_PATHS = [
        'Magento_Sales' => '/app/vendor/magento/module-sales',
        'Mailchimp_Ebizmarts' => '/app/vendor/mailchimp/mc-magento2',
        'Avalon_ConditionsCron' => '/app/app/code/Avalon/ConditionsCron',
    ];

    private const VIRTUAL_TYPES = [
        'SalesOrderIndexGridAsyncInsertCron' => 'Magento\Sales\Model\ResourceModel\Grid',
    ];

    private const COMPOSER_NAMES = [
        '/app/vendor/magento/module-sales/composer.json' => '{"name":"magento/module-sales"}',
        '/app/vendor/mailchimp/mc-magento2/composer.json' => '{"name":"mailchimp/mc-magento2"}',
    ];

    public function testResolvesAClassToItsDeclaringModule(): void
    {
        self::assertSame(
            'Magento_Sales',
            $this->attribution()->moduleForClass('Magento\Sales\Cron\CleanExpiredQuotes')
        );
    }

    /**
     * Stock Magento names virtual types in eight of its own sales cron jobs.
     * These carry no namespace at all, so without DI resolution they land in
     * the unattributed bucket beside a merchant's bespoke integrations.
     */
    public function testResolvesADiVirtualTypeToTheModuleBehindIt(): void
    {
        self::assertSame(
            'Magento_Sales',
            $this->attribution()->moduleForClass('SalesOrderIndexGridAsyncInsertCron')
        );
    }

    /**
     * Magento's framework ships as a library, not a module, so it is in no
     * registrar entry. Attributing it to Magento anyway keeps jobs like
     * messagequeue_clean_outdated_locks out of the unattributed bucket.
     */
    public function testAttributesFrameworkClassesToMagentoRatherThanNothing(): void
    {
        $module = $this->attribution()->moduleForClass('Magento\Framework\MessageQueue\Lock\WriterInterface');

        self::assertSame(ModuleAttribution::FRAMEWORK_MODULE, $module);
        self::assertTrue($this->attribution()->isMagentoCore($module));
    }

    public function testReturnsNullForAClassBelongingToNoInstalledModule(): void
    {
        self::assertNull($this->attribution()->moduleForClass('Some\Unregistered\Thing'));
    }

    public function testReturnsNullForANameWithNoNamespaceThatIsNotAVirtualType(): void
    {
        self::assertNull($this->attribution()->moduleForClass('NotAThing'));
    }

    public function testReadsTheComposerPackageName(): void
    {
        self::assertSame('mailchimp/mc-magento2', $this->attribution()->packageFor('Mailchimp_Ebizmarts'));
    }

    /**
     * The whole point of attribution: the job codes are all ebizmarts_*, but
     * the merchant bought this as Mailchimp.
     */
    public function testVendorLabelPrefersTheComposerVendorOverTheModuleName(): void
    {
        self::assertSame('Mailchimp', $this->attribution()->vendorLabelFor('Mailchimp_Ebizmarts'));
    }

    public function testVendorLabelFallsBackToTheModuleVendorWithoutAComposerJson(): void
    {
        // A module dropped straight into app/code frequently has none.
        self::assertNull($this->attribution()->packageFor('Avalon_ConditionsCron'));
        self::assertSame('Avalon', $this->attribution()->vendorLabelFor('Avalon_ConditionsCron'));
    }

    public function testDistinguishesMagentoCoreFromThirdParty(): void
    {
        $attribution = $this->attribution();

        self::assertTrue($attribution->isMagentoCore('Magento_Sales'));
        self::assertFalse($attribution->isMagentoCore('Mailchimp_Ebizmarts'));
    }

    /**
     * Builds an attribution over a fixed, fake install.
     *
     * @return ModuleAttribution
     */
    private function attribution(): ModuleAttribution
    {
        $registrar = $this->createStub(ComponentRegistrarInterface::class);
        $registrar->method('getPaths')->willReturn(self::MODULE_PATHS);

        $objectManagerConfig = $this->createStub(ObjectManagerConfigInterface::class);
        $objectManagerConfig->method('getInstanceType')->willReturnCallback(
            static fn (string $name): string => self::VIRTUAL_TYPES[$name] ?? $name
        );

        $fileDriver = $this->createStub(File::class);
        $fileDriver->method('isExists')->willReturnCallback(
            static fn (string $path): bool => isset(self::COMPOSER_NAMES[$path])
        );
        $fileDriver->method('fileGetContents')->willReturnCallback(
            static fn (string $path): string => self::COMPOSER_NAMES[$path] ?? ''
        );

        return new ModuleAttribution($registrar, $objectManagerConfig, $fileDriver);
    }
}
