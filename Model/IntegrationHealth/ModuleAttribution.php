<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Model\IntegrationHealth;

use Magento\Framework\Component\ComponentRegistrar;
use Magento\Framework\Component\ComponentRegistrarInterface;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Filesystem\Driver\File;
use Magento\Framework\ObjectManager\ConfigInterface as ObjectManagerConfigInterface;

/**
 * Answers "whose is this?" for a cron job or queue consumer, by mapping the
 * class that implements it back to the module that shipped it, and that
 * module back to a name the merchant recognizes.
 *
 * This is the half of discovery that regularity cannot do. A job code is an
 * internal identifier that frequently does not contain the vendor's name at
 * all: Mailchimp ships as mailchimp/mc-magento2 but every one of its jobs is
 * called ebizmarts_*, so a merchant scanning the raw codes for "mailchimp"
 * finds nothing. The declaring module is the bridge between the two.
 */
class ModuleAttribution
{
    /**
     * Magento's own framework code is part of Magento but ships as a library
     * rather than a module, so it appears in no registrar entry. Naming it
     * here keeps jobs like messagequeue_clean_outdated_locks attributed to
     * Magento instead of falling into the unattributed bucket beside a
     * merchant's bespoke integrations.
     */
    public const FRAMEWORK_MODULE = 'Magento_Framework';

    /**
     * Module name => absolute path, read once per request.
     *
     * @var array<string,string>|null
     */
    private ?array $modulePaths = null;

    /**
     * Module name => Composer package name, or false when it has none.
     *
     * @var array<string,string|false>
     */
    private array $packageNames = [];

    /**
     * @param ComponentRegistrarInterface $componentRegistrar
     * @param ObjectManagerConfigInterface $objectManagerConfig
     * @param File $fileDriver
     */
    public function __construct(
        private readonly ComponentRegistrarInterface $componentRegistrar,
        private readonly ObjectManagerConfigInterface $objectManagerConfig,
        private readonly File $fileDriver
    ) {
    }

    /**
     * The module that declared a class, or null when it belongs to none.
     *
     * Magento's module namespaces are always Vendor\Module\..., so the first
     * two segments name the module. The result is checked against the
     * registrar rather than trusted: a cron job may point at a class in a
     * library namespace that looks like a module and is not one.
     *
     * @param string $className may be a DI virtual type rather than a real class
     * @return string|null
     */
    public function moduleForClass(string $className): ?string
    {
        $resolved = $this->resolveVirtualType($className);
        $parts = explode('\\', ltrim($resolved, '\\'));

        if (count($parts) < 2) {
            return null;
        }

        $module = $parts[0] . '_' . $parts[1];

        if (isset($this->modulePaths()[$module])) {
            return $module;
        }

        return str_starts_with($resolved, 'Magento\\Framework\\') ? self::FRAMEWORK_MODULE : null;
    }

    /**
     * Resolves a DI virtual type to the concrete class behind it.
     *
     * Cron jobs name virtual types surprisingly often. Eight of stock
     * Magento's own sales jobs point at names like
     * SalesOrderIndexGridAsyncInsertCron, which carry no namespace at all and
     * would otherwise be unattributable. getInstanceType() already follows a
     * chain of virtual types extending virtual types, and returns the name
     * unchanged when it is a real class.
     *
     * @param string $name
     * @return string
     */
    private function resolveVirtualType(string $name): string
    {
        if ($name === '') {
            return $name;
        }

        try {
            $resolved = (string) $this->objectManagerConfig->getInstanceType($name);
        } catch (\Throwable) {
            // Attribution is a naming nicety; never let it break discovery.
            return $name;
        }

        return $resolved !== '' ? $resolved : $name;
    }

    /**
     * The Composer package a module ships as, or null for one that has no composer.json.
     *
     * Modules dropped straight into app/code frequently have none, which is
     * not an error: the module name is then the only identifier there is.
     *
     * @param string $module
     * @return string|null
     */
    public function packageFor(string $module): ?string
    {
        if (!array_key_exists($module, $this->packageNames)) {
            $this->packageNames[$module] = $this->readPackageName($module);
        }

        return $this->packageNames[$module] === false ? null : $this->packageNames[$module];
    }

    /**
     * A merchant-recognizable label for whoever ships this module.
     *
     * Taken from the Composer vendor segment where there is one, since that
     * is the name a merchant bought the extension under, falling back to the
     * module's own vendor segment. Deliberately a mechanical humanization
     * rather than a lookup table of marketing names: "M2epro" is honest about
     * what the install actually says, where guessing "M2E Pro" would be
     * inventing information the connector does not have.
     *
     * @param string $module
     * @return string
     */
    public function vendorLabelFor(string $module): string
    {
        $package = $this->packageFor($module);
        $vendor = $package !== null ? explode('/', $package)[0] : explode('_', $module)[0];

        return $this->humanize($vendor);
    }

    /**
     * Whether this module is part of Magento itself rather than something the merchant added.
     *
     * Used to rank discovered integrations, not to hide core modules: a
     * merchant may legitimately want to watch a core job, they just should
     * not have to scroll past 60 of them to reach their ERP sync.
     *
     * @param string $module
     * @return bool
     */
    public function isMagentoCore(string $module): bool
    {
        return str_starts_with($module, 'Magento_');
    }

    /**
     * Turns a vendor slug into something presentable.
     *
     * @param string $value
     * @return string
     */
    private function humanize(string $value): string
    {
        return ucwords(trim(str_replace(['-', '_', '.'], ' ', $value)));
    }

    /**
     * Reads a module's Composer package name, or false when it has none.
     *
     * @param string $module
     * @return string|false
     */
    private function readPackageName(string $module): string|false
    {
        $path = $this->modulePaths()[$module] ?? null;

        if ($path === null) {
            return false;
        }

        try {
            $composerPath = $path . '/composer.json';

            if (!$this->fileDriver->isExists($composerPath)) {
                return false;
            }

            $decoded = json_decode($this->fileDriver->fileGetContents($composerPath), true);
        } catch (FileSystemException) {
            // An unreadable composer.json is a naming inconvenience, never a
            // reason to fail discovery: the module name still identifies it.
            return false;
        }

        return is_array($decoded) && isset($decoded['name']) && is_string($decoded['name'])
            ? $decoded['name']
            : false;
    }

    /**
     * Every registered module's path, read once and reused.
     *
     * @return array<string,string>
     */
    private function modulePaths(): array
    {
        if ($this->modulePaths === null) {
            $this->modulePaths = $this->componentRegistrar->getPaths(ComponentRegistrar::MODULE);
        }

        return $this->modulePaths;
    }
}
