<?php

declare(strict_types=1);

namespace Watchtower\Connector\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;

/**
 * The only class in this module permitted to read Watchtower's own
 * configuration paths directly. Every other service depends on this class
 * instead, so the config paths below have exactly one call site to update.
 */
class Config
{
    private const XML_PATH_BASE_URL = 'watchtower/general/base_url';
    private const XML_PATH_API_KEY = 'watchtower/general/api_key';
    private const XML_PATH_ENABLED = 'watchtower/general/enabled';

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param EncryptorInterface $encryptor
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly EncryptorInterface $encryptor
    ) {
    }

    /**
     * The configured platform base URL, with any trailing slash stripped.
     *
     * @return string|null
     */
    public function baseUrl(): ?string
    {
        $value = $this->scopeConfig->getValue(self::XML_PATH_BASE_URL);

        return $value !== null ? rtrim((string) $value, '/') : null;
    }

    /**
     * The field's backend_model (Magento\Config\Model\Config\Backend\Encrypted)
     * encrypts the value on save; ScopeConfigInterface::getValue() returns the
     * raw ciphertext, so it must be decrypted explicitly here.
     */
    public function apiKey(): ?string
    {
        $value = $this->scopeConfig->getValue(self::XML_PATH_API_KEY);

        if ($value === null || $value === '') {
            return null;
        }

        // EncryptorInterface::decrypt() returns '' (not an exception) for a
        // payload it can't decrypt, e.g. the encryption key was rotated
        // since this was saved. Normalize that back to null so isConfigured()
        // correctly reports "not configured" instead of the connector
        // sending "Authorization: Bearer " with an empty token and getting
        // an opaque 401.
        $decrypted = $this->encryptor->decrypt((string) $value);

        return $decrypted !== '' ? $decrypted : null;
    }

    /**
     * Whether the connector is enabled.
     *
     * @return bool
     */
    public function isEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED);
    }

    /**
     * Whether both a base URL and API key are saved.
     *
     * @return bool
     */
    public function isConfigured(): bool
    {
        return $this->baseUrl() !== null && $this->apiKey() !== null;
    }
}
