<?php

declare(strict_types=1);

namespace Retnly\MagentoBridge\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Retnly\MagentoBridge\Model\EventOutbox;

/**
 * Pushes a product to Retnly the moment it is saved or deleted.
 *
 * WHY
 *   Retnly's catalogue copy (`MagentoProduct`) was refreshed once a day by
 *   `sync_all_magento_products_task`, so a price change could be up to 24 hours
 *   stale. Iris quotes prices from that table and the brand-asset retriever
 *   picks photos from it -- neither reads the live Magento API -- so the table
 *   being a day behind means the merchant's customers are quoted yesterday's
 *   price. Shopify closed this window with a product webhook; this is the
 *   Magento equivalent.
 *
 * WHY THE OUTBOX
 *   Same reasoning as the order observers: enqueue inside the merchant's own
 *   transaction and let the per-minute cron deliver. A slow or unreachable
 *   Retnly must never make saving a product in the admin hang or fail.
 *
 * DELETES
 *   `catalog_product_delete_after` carries the product, so the id is still
 *   readable at that point. It is sent with action=delete rather than as a
 *   bare id so the receiver needs only one endpoint and one payload shape.
 */
class ProductChangedObserver implements ObserverInterface
{
    private const ENDPOINT = 'products/webhook/';

    private EventOutbox $outbox;

    public function __construct(EventOutbox $outbox)
    {
        $this->outbox = $outbox;
    }

    public function execute(Observer $observer): void
    {
        $product = $observer->getEvent()->getProduct();
        if (!$product || !$product->getId()) {
            return;
        }

        $isDelete = $observer->getEvent()->getName() === 'catalog_product_delete_after';

        $payload = [
            'action' => $isDelete ? 'delete' : 'save',
            'id'     => (int) $product->getId(),
            'sku'    => (string) $product->getSku(),
        ];

        if (!$isDelete) {
            $payload += [
                'name'       => (string) $product->getName(),
                'status'     => (int) $product->getStatus(),
                'type_id'    => (string) $product->getTypeId(),
                'price'      => $product->getPrice() !== null ? (float) $product->getPrice() : null,
                'created_at' => $product->getCreatedAt(),
                'updated_at' => $product->getUpdatedAt(),
                // Same shape the REST sync returns, so the receiver reuses
                // `_build_product_images` rather than growing a second parser.
                'media_gallery_entries' => $this->galleryEntries($product),
            ];
        }

        // Keyed on id + action + updated_at so a genuine later edit is its own
        // event, while a double-save of the same state de-dupes.
        $this->outbox->enqueue(
            self::ENDPOINT,
            $payload,
            sprintf(
                'product-%d-%s-%s',
                (int) $product->getId(),
                $isDelete ? 'delete' : 'save',
                (string) ($product->getUpdatedAt() ?? '')
            )
        );
    }

    /**
     * Gallery in the REST shape. Returns [] when the product was loaded without
     * its media -- a price-only save often is -- and the receiver deliberately
     * leaves the stored images alone when this is empty, rather than blanking
     * the catalogue one photo at a time.
     */
    private function galleryEntries($product): array
    {
        $entries = [];
        try {
            foreach ((array) $product->getMediaGalleryEntries() as $entry) {
                $entries[] = [
                    'media_type' => (string) $entry->getMediaType(),
                    'file'       => (string) $entry->getFile(),
                    'label'      => (string) $entry->getLabel(),
                    'position'   => (int) $entry->getPosition(),
                ];
            }
        } catch (\Exception $e) {
            return [];
        }
        return $entries;
    }
}
