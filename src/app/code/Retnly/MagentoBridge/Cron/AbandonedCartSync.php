<?php

declare(strict_types=1);

namespace Retnly\MagentoBridge\Cron;

use Magento\Framework\App\ResourceConnection;
use Magento\Quote\Model\ResourceModel\Quote\CollectionFactory as QuoteCollectionFactory;
use Psr\Log\LoggerInterface;
use Retnly\MagentoBridge\Helper\Api;

class AbandonedCartSync
{
    /**
     * How long a quote must sit untouched before it counts as abandoned.
     *
     * Matches Retnly's Shopify side (`ABANDONED_CART_FIRE_DELAY_MINUTES`,
     * settings.py, default 30) so a merchant running both storefronts does not
     * get two different definitions of "abandoned" on one dashboard.
     */
    private const ABANDONED_AFTER_MINUTES = 30;

    /**
     * Floor on cart age. A quote older than this is never sent.
     *
     * This matters as much as the delay above it, and its absence was a live
     * hazard: with only an upper bound, the FIRST run against a store with any
     * history treats every quote ever abandoned as due and fires a recovery
     * message for each one. Magento keeps `is_active=1` quotes around
     * indefinitely, so on a real store that is potentially years of them.
     *
     * The floor also encodes the obvious product rule — nobody is recovered by
     * a nudge about a basket they walked away from last spring. Mirrors
     * `ABANDONED_CART_MAX_AGE_HOURS` (default 24) on the Shopify side.
     */
    private const MAX_AGE_HOURS = 24;

    private const SYNC_TABLE = 'retnly_abandoned_cart_sync';

    private Api $api;
    private QuoteCollectionFactory $quoteCollectionFactory;
    private ResourceConnection $resourceConnection;
    private LoggerInterface $logger;

    public function __construct(
        Api $api,
        QuoteCollectionFactory $quoteCollectionFactory,
        ResourceConnection $resourceConnection,
        LoggerInterface $logger
    ) {
        $this->api                    = $api;
        $this->quoteCollectionFactory = $quoteCollectionFactory;
        $this->resourceConnection     = $resourceConnection;
        $this->logger                 = $logger;
    }

    public function execute(): void
    {
        if (!$this->api->isEnabled()) {
            return;
        }

        $cutoff     = date('Y-m-d H:i:s', strtotime('-' . self::ABANDONED_AFTER_MINUTES . ' minutes'));
        $staleFloor = date('Y-m-d H:i:s', strtotime('-' . self::MAX_AGE_HOURS . ' hours'));
        $syncTable  = $this->resourceConnection->getTableName(self::SYNC_TABLE);
        $conn       = $this->resourceConnection->getConnection();

        $collection = $this->quoteCollectionFactory->create();
        $collection->addFieldToFilter('is_active', 1)
                   ->addFieldToFilter('customer_email', ['notnull' => true])
                   ->addFieldToFilter('items_count', ['gt' => 0])
                   // Between the floor and the cutoff: old enough to be
                   // abandoned, recent enough to be worth recovering.
                   ->addFieldToFilter('updated_at', ['lt' => $cutoff])
                   ->addFieldToFilter('updated_at', ['gteq' => $staleFloor]);

        // Skip quotes already synced to Retnly. The LEFT JOIN with IS NULL filter
        // is what makes this cron idempotent: a quote is sent exactly once.
        $collection->getSelect()->joinLeft(
            ['retnly_sync' => $syncTable],
            'main_table.entity_id = retnly_sync.quote_id',
            []
        )->where('retnly_sync.synced_at IS NULL');

        foreach ($collection as $quote) {
            $items = [];
            foreach ($quote->getAllVisibleItems() as $item) {
                $items[] = [
                    'sku'        => $item->getSku(),
                    'name'       => $item->getName(),
                    'qty'        => (float) $item->getQty(),
                    'price'      => (float) $item->getPrice(),
                    'row_total'  => (float) $item->getRowTotal(),
                    'product_id' => (int) $item->getProductId(),
                ];
            }

            $telephone = $this->resolveTelephone($quote);

            $payload = [
                'store_id'           => $this->api->getStoreId(),
                'quote_id'           => (int) $quote->getId(),
                'customer_email'     => $quote->getCustomerEmail(),
                'customer_firstname' => $quote->getCustomerFirstname(),
                'customer_lastname'  => $quote->getCustomerLastname(),
                'customer_id'        => $quote->getCustomerId() !== null
                                            ? (int) $quote->getCustomerId()
                                            : null,
                // Nested under billing_address because that is where the
                // receiver's `_get_or_create_merchant_customer` looks for a
                // telephone -- the same shape the order payload uses. Sending it
                // anywhere else would need a second code path on the Python side.
                'billing_address'    => ['telephone' => $telephone],
                'grand_total'        => (float) $quote->getGrandTotal(),
                'subtotal'           => (float) $quote->getSubtotal(),
                'items_count'        => (int) $quote->getItemsCount(),
                'items'              => $items,
                'currency_code'      => $quote->getQuoteCurrencyCode(),
                'created_at'         => $quote->getCreatedAt(),
                'updated_at'         => $quote->getUpdatedAt(),
            ];

            $status = $this->api->post('abandoned-carts/', $payload);

            if ($status !== null && $status >= 200 && $status < 300) {
                try {
                    $conn->insert($syncTable, [
                        'quote_id'  => (int) $quote->getId(),
                        'synced_at' => date('Y-m-d H:i:s'),
                    ]);
                } catch (\Exception $e) {
                    // Race: quote could be deleted between SELECT and INSERT,
                    // or another process inserted the same row. Log and keep going
                    // so a single bad row does not abort the whole cron run.
                    $this->logger->warning(sprintf(
                        '[Retnly] Failed to record sync state for quote_id=%d: %s',
                        (int) $quote->getId(),
                        $e->getMessage()
                    ));
                }
            }
            // Failed POSTs (non-2xx or null): no row written, so the cart is picked
            // up again on the next cron run until it succeeds.
        }
    }

    /**
     * The shopper's number, billing first then shipping.
     *
     * Magento fills the billing address at the payment step but the shipping
     * address one step earlier, so a cart abandoned mid-checkout frequently has
     * a shipping telephone and no billing one. Checking only billing -- the
     * obvious implementation -- would drop the number for exactly the carts
     * this feature exists to recover.
     */
    private function resolveTelephone($quote): string
    {
        foreach (['getBillingAddress', 'getShippingAddress'] as $getter) {
            try {
                $address = $quote->{$getter}();
            } catch (\Exception $e) {
                continue;
            }
            if (!$address) {
                continue;
            }
            $telephone = trim((string) $address->getTelephone());
            if ($telephone !== '') {
                return $telephone;
            }
        }
        return '';
    }
}
