<?php

declare(strict_types=1);

namespace Watchtower\Connector\Block\Adminhtml\IntegrationHealth;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Store\Api\Data\StoreInterface;
use Watchtower\Connector\Model\IntegrationHealth\IntegrationHealthConfig;
use Watchtower\Connector\Model\IntegrationHealth\IntegrationHealthConfigRepository;
use Watchtower\Connector\Model\IntegrationHealth\IntegrationHealthConfigValidator;
use Watchtower\Connector\Model\StoreView\LiveStoreViewResolver;

/**
 * Backs the integration_health source picker page: the live store views to
 * render a row for, plus whatever source each already has configured.
 */
class Sources extends Template
{
    /**
     * Pre-filled for an unconfigured row, not enforced -- an admin still must
     * Save before anything persists. Double CronHealth\Evaluator's own
     * EXPECTED_MAX_INTERVAL_MINUTES (30), since integration_health sources are
     * typically lower-frequency batch jobs (queue consumers, ERP syncs) than
     * the scheduler heartbeat, and a tighter default would risk false DOWN
     * reports on healthy but infrequent integrations.
     */
    public const DEFAULT_EXPECTED_MAX_INTERVAL_MINUTES = 60;

    /**
     * @var array<int, IntegrationHealthConfig>|null
     */
    private ?array $configs = null;

    /**
     * @param Context $context
     * @param LiveStoreViewResolver $liveStoreViewResolver
     * @param IntegrationHealthConfigRepository $configRepository
     * @param array $data
     */
    public function __construct(
        Context $context,
        private readonly LiveStoreViewResolver $liveStoreViewResolver,
        private readonly IntegrationHealthConfigRepository $configRepository,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * Every currently-active store view, one picker row each.
     *
     * @return StoreInterface[]
     */
    public function getLiveStoreViews(): array
    {
        return $this->liveStoreViewResolver->all();
    }

    /**
     * The already-configured source for a store view, or null if it has none.
     *
     * Backed by a single getAll() read cached for the whole render, so the
     * page stays at one query regardless of how many store views exist.
     *
     * @param int $storeViewId
     * @return IntegrationHealthConfig|null
     */
    public function getConfigFor(int $storeViewId): ?IntegrationHealthConfig
    {
        if ($this->configs === null) {
            $this->configs = $this->configRepository->getAll();
        }

        return $this->configs[$storeViewId] ?? null;
    }

    /**
     * The three selectable source types, keyed by stored value with a display label.
     *
     * @return array<string, string>
     */
    public function getSourceTypeOptions(): array
    {
        return [
            IntegrationHealthConfig::SOURCE_TYPE_CRON_JOB => (string) __('Cron job'),
            IntegrationHealthConfig::SOURCE_TYPE_QUEUE_CONSUMER => (string) __('Queue consumer'),
            IntegrationHealthConfig::SOURCE_TYPE_CONVENTION_EVENT => (string) __('Convention event'),
        ];
    }

    /**
     * The interval pre-filled for an unconfigured row; not persisted until Save is clicked.
     *
     * @return int
     */
    public function getDefaultExpectedMaxIntervalMinutes(): int
    {
        return self::DEFAULT_EXPECTED_MAX_INTERVAL_MINUTES;
    }

    /**
     * Upper bound for the interval field, mirroring IntegrationHealthConfigValidator.
     *
     * @return int
     */
    public function getMaxExpectedMaxIntervalMinutes(): int
    {
        return IntegrationHealthConfigValidator::MAX_EXPECTED_MAX_INTERVAL_MINUTES;
    }

    /**
     * Max length for a convention-event label, mirroring IntegrationHealthConfigValidator.
     *
     * @return int
     */
    public function getMaxConventionEventLabelLength(): int
    {
        return IntegrationHealthConfigValidator::MAX_CONVENTION_EVENT_LABEL_LENGTH;
    }

    /**
     * URL the picker POSTs a row's configuration to.
     *
     * @return string
     */
    public function getSaveUrl(): string
    {
        return $this->getUrl('watchtower/integrationhealth/save');
    }

    /**
     * URL the picker POSTs to when clearing a row's configuration.
     *
     * @return string
     */
    public function getDeleteUrl(): string
    {
        return $this->getUrl('watchtower/integrationhealth/delete');
    }

    /**
     * URL the picker reads the selectable cron job codes from.
     *
     * @return string
     */
    public function getCronJobsUrl(): string
    {
        return $this->getUrl('watchtower/integrationhealth/cronjobs');
    }

    /**
     * URL the picker reads the selectable queue topics from.
     *
     * @return string
     */
    public function getQueueTopicsUrl(): string
    {
        return $this->getUrl('watchtower/integrationhealth/queuetopics');
    }
}
