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
 * Answers "whose is this?" for a cron job or consumer, mapping the class that
 * implements it back to the module that shipped it, and on to a name the
 * merchant recognizes.
 *
 * Needed because a job code often lacks the vendor's name entirely: Mailchimp
 * ships as mailchimp/mc-magento2 but its jobs are all ebizmarts_*.
 */
class ModuleAttribution
{
    /**
     * The framework ships as a library, not a module, so it appears in no
     * registrar entry. Named here so its jobs attribute to Magento.
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
     * Eight of stock Magento's sales jobs name virtual types carrying no
     * namespace at all, which would otherwise be unattributable.
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
     * Mechanical humanization of the Composer vendor, not a lookup table of
     * marketing names: "M2epro" is honest, guessing "M2E Pro" invents facts.
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
     * The name to show a merchant for this module.
     *
     * Built from the module name rather than the Composer description. The
     * description looked like the obvious source and is not: vendors put
     * whatever they like in it. Amasty writes product names, M2E writes a
     * marketing paragraph, and PayPal_Braintree writes "Fork from the Magento
     * Braintree 2.2.0 module by Gene", which is worse than no name at all.
     *
     * The module name is always present, always distinct, and uses the
     * vendor's own capitalisation, so three Amasty modules become "Amasty
     * Acart", "Amasty Base" and "Amasty CronScheduleList" rather than three
     * rows all reading "Amasty".
     *
     * @param string $module
     * @return string
     */
    public function displayNameFor(string $module): string
    {
        // Only the underscore is split. Splitting CamelCase too was tried and
        // reverted: it turns PayPal_Braintree into "Pay Pal Braintree" and
        // MailChimp into "Mail Chimp". An unspaced compound like
        // "CronScheduleList" is merely less pretty, whereas a mangled brand
        // name is wrong, and Magento users read module names constantly.
        $spaced = str_replace('_', ' ', $module);

        $words = [];

        foreach (explode(' ', $spaced) as $word) {
            // Salesfire_Salesfire would otherwise read "Salesfire Salesfire".
            if ($word !== '' && strcasecmp($word, (string) end($words)) !== 0) {
                $words[] = $word;
            }
        }

        return implode(' ', $words);
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
        $name = $this->readComposerField($module, 'name');

        return $name ?? false;
    }

    /**
     * Reads one field from a module's composer.json, or null when unavailable.
     *
     * @param string $module
     * @param string $field
     * @return string|null
     */
    private function readComposerField(string $module, string $field): ?string
    {
        $path = $this->modulePaths()[$module] ?? null;

        if ($path === null) {
            return null;
        }

        try {
            $composerPath = $path . '/composer.json';

            if (!$this->fileDriver->isExists($composerPath)) {
                return null;
            }

            $decoded = json_decode($this->fileDriver->fileGetContents($composerPath), true);
        } catch (FileSystemException) {
            // An unreadable composer.json is a naming inconvenience, never a
            // reason to fail discovery: the module name still identifies it.
            return null;
        }

        return is_array($decoded) && isset($decoded[$field]) && is_string($decoded[$field])
            ? $decoded[$field]
            : null;
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
