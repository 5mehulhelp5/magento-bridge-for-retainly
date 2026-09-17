<?php

declare(strict_types=1);

namespace Retnly\MagentoBridge\Controller\Sw;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Serves the Firebase messaging service worker.
 *
 * WHY A CONTROLLER AND NOT A STATIC FILE
 *   A service worker may only control pages at or below its own path, and
 *   Magento serves static assets from a deep, version-stamped directory
 *   (/static/version.../frontend/Vendor/theme/en_US/...). A worker registered
 *   from there could never claim the storefront root, so background messages
 *   would be dropped on every page.
 *
 *   The `Service-Worker-Allowed: /` header is the sanctioned escape hatch: it
 *   lets a worker served from one path register for a broader scope. That
 *   header cannot be set on a static asset, which is why this is a controller.
 *
 * WHY THE CONFIG IS INLINED
 *   A service worker runs outside the page, so it cannot read the config the
 *   template hands to the pixel. Firebase must be initialised inside the worker
 *   with the same credentials, so they are rendered into the body here.
 */
class Messaging implements HttpGetActionInterface
{
    private const FIREBASE_FIELDS = [
        'apiKey'            => 'zeroslip/pixel/firebase_api_key',
        'projectId'         => 'zeroslip/pixel/firebase_project_id',
        'messagingSenderId' => 'zeroslip/pixel/firebase_sender_id',
        'appId'             => 'zeroslip/pixel/firebase_app_id',
    ];

    private RawFactory $rawFactory;
    private ScopeConfigInterface $scopeConfig;

    public function __construct(
        RawFactory $rawFactory,
        ScopeConfigInterface $scopeConfig
    ) {
        $this->rawFactory  = $rawFactory;
        $this->scopeConfig = $scopeConfig;
    }

    public function execute(): ResultInterface
    {
        $result = $this->rawFactory->create();
        $result->setHeader('Content-Type', 'application/javascript', true);
        // Without this the browser refuses the `{scope: '/'}` registration the
        // pixel asks for, and getToken() fails with an opaque scope error.
        $result->setHeader('Service-Worker-Allowed', '/', true);
        // Short, not zero: the worker embeds credentials that a merchant can
        // change in admin, and a permanently cached copy would keep using the
        // old ones. Browsers re-check a service worker at least every 24h anyway.
        $result->setHeader('Cache-Control', 'max-age=3600', true);

        $config = [];
        foreach (self::FIREBASE_FIELDS as $key => $path) {
            $config[$key] = trim((string) $this->scopeConfig->getValue(
                $path,
                ScopeInterface::SCOPE_STORE
            ));
        }

        // Credentials absent means push was never configured. Serve a valid but
        // inert worker rather than a 404: the pixel only registers when push is
        // enabled, and a 404 here would show up as a console error on a
        // storefront whose merchant simply did not want push.
        if (in_array('', $config, true)) {
            return $result->setContents("// Retnly: web push is not configured for this store.\n");
        }

        $json = json_encode($config, JSON_UNESCAPED_SLASHES);

        $body = <<<JS
// Retnly Magento bridge - Firebase messaging service worker (generated).
importScripts('https://www.gstatic.com/firebasejs/10.12.2/firebase-app-compat.js');
importScripts('https://www.gstatic.com/firebasejs/10.12.2/firebase-messaging-compat.js');

firebase.initializeApp({$json});

var messaging = firebase.messaging();

messaging.onBackgroundMessage(function (payload) {
    var notification = payload.notification || {};
    var data = payload.data || {};
    self.registration.showNotification(notification.title || 'New message', {
        body: notification.body || '',
        icon: notification.icon || data.icon || undefined,
        data: { click_url: data.click_url || notification.click_action || '/' }
    });
});

self.addEventListener('notificationclick', function (event) {
    event.notification.close();
    var url = (event.notification.data && event.notification.data.click_url) || '/';
    // focus an existing tab on the same origin before opening a new one,
    // otherwise every click leaves the shopper another duplicate tab.
    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (list) {
            for (var i = 0; i < list.length; i++) {
                if (list[i].url === url && 'focus' in list[i]) {
                    return list[i].focus();
                }
            }
            return clients.openWindow ? clients.openWindow(url) : undefined;
        })
    );
});
JS;

        return $result->setContents($body);
    }
}
