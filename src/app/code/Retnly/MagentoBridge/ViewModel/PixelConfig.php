<?php

declare(strict_types=1);

namespace Retnly\MagentoBridge\ViewModel;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Everything the storefront pixel needs, resolved server-side.
 *
 * Nothing secret is exposed here on purpose. The API key used by the PHP
 * observers is a BEARER token with write access, and the pixel endpoint
 * (api/v4/magento/event/) is deliberately unauthenticated — it identifies the
 * store by domain, with the merchant slug as a fallback. So the browser is
 * handed the store domain and the slug, never the API key.
 */
class PixelConfig implements ArgumentInterface
{
    private const XML_PATH_PIXEL_ENABLED = 'zeroslip/pixel/enabled';
    private const XML_PATH_PUSH_ENABLED  = 'zeroslip/pixel/push_enabled';
    private const XML_PATH_API_BASE_URL  = 'zeroslip/general/api_base_url';
    private const XML_PATH_ENABLED       = 'zeroslip/general/enabled';
    private const XML_PATH_STORE_ID      = 'zeroslip/general/store_id';

    private const FIREBASE_FIELDS = [
        'apiKey'            => 'zeroslip/pixel/firebase_api_key',
        'projectId'         => 'zeroslip/pixel/firebase_project_id',
        'messagingSenderId' => 'zeroslip/pixel/firebase_sender_id',
        'appId'             => 'zeroslip/pixel/firebase_app_id',
    ];

    private ScopeConfigInterface $scopeConfig;
    private StoreManagerInterface $storeManager;

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        StoreManagerInterface $storeManager
    ) {
        $this->scopeConfig  = $scopeConfig;
        $this->storeManager = $storeManager;
    }

    /**
     * The pixel is gated on BOTH the master integration switch and its own,
     * so turning Retnly off in one place cannot leave the storefront still
     * chattering to a backend the merchant thinks is disconnected.
     */
    public function isEnabled(): bool
    {
        return $this->flag(self::XML_PATH_ENABLED)
            && $this->flag(self::XML_PATH_PIXEL_ENABLED);
    }

    public function isPushEnabled(): bool
    {
        if (!$this->isEnabled() || !$this->flag(self::XML_PATH_PUSH_ENABLED)) {
            return false;
        }
        // Half-filled Firebase credentials produce an opaque SDK failure in the
        // browser. Treat incomplete config as "off" so the console stays clean
        // and the merchant sees the fields they still owe us in admin instead.
        foreach (self::FIREBASE_FIELDS as $value) {
            if ($this->value($value) === '') {
                return false;
            }
        }
        return $this->value('zeroslip/pixel/vapid_key') !== '';
    }

    /**
     * The whole config object, ready to be JSON-encoded into the page.
     */
    public function getConfig(): array
    {
        return [
            'endpoint'    => rtrim($this->value(self::XML_PATH_API_BASE_URL), '/') . '/event/',
            'storeDomain' => $this->getStoreDomain(),
            'appKey'      => $this->value(self::XML_PATH_STORE_ID),
            'push'        => [
                'enabled'  => $this->isPushEnabled(),
                'vapidKey' => $this->isPushEnabled() ? $this->value('zeroslip/pixel/vapid_key') : '',
                'firebase' => $this->isPushEnabled() ? $this->firebaseConfig() : new \stdClass(),
            ],
        ];
    }

    /**
     * Host only, no scheme and no trailing slash.
     *
     * The receiver normalises these itself, but sending the bare host keeps the
     * payload matching the `store_url` column a merchant typed into the Retnly
     * dashboard, which is what the direct MagentoStore lookup compares against.
     */
    public function getStoreDomain(): string
    {
        $base = (string) $this->storeManager->getStore()->getBaseUrl();
        $host = parse_url($base, PHP_URL_HOST);
        return is_string($host) && $host !== '' ? $host : rtrim($base, '/');
    }

    private function firebaseConfig(): array
    {
        $out = [];
        foreach (self::FIREBASE_FIELDS as $key => $path) {
            $out[$key] = $this->value($path);
        }
        return $out;
    }

    private function value(string $path): string
    {
        return trim((string) $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE));
    }

    private function flag(string $path): bool
    {
        return (bool) $this->scopeConfig->isSetFlag($path, ScopeInterface::SCOPE_STORE);
    }
}
