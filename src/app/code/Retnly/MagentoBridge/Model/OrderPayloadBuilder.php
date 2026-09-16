<?php

declare(strict_types=1);

namespace Retnly\MagentoBridge\Model;

use Magento\Sales\Model\Order;
use Retnly\MagentoBridge\Helper\Api;

/**
 * Builds the order payload POSTed to Retnly.
 *
 * Every order observer sends the same shape; only `event_type` and a handful of
 * event-specific extras (e.g. refund_amount) differ. This lived as four identical
 * copies across the observers, so a field added for one event silently went
 * missing from the other three.
 *
 * `event_type` is the important one: all order events POST to the same `orders/`
 * endpoint, so without it the receiver has to guess the event from `$order`'s
 * state — which mislabels a PARTIAL credit memo as "paid", because a partial
 * refund leaves the order in `processing`. The observer already knows which
 * event it is; this makes it say so.
 */
class OrderPayloadBuilder
{
    private Api $api;

    public function __construct(Api $api)
    {
        $this->api = $api;
    }

    /**
     * @param string  $eventType One of the retnly event slugs, e.g. magento_order_placed.
     * @param mixed[] $extra     Event-specific fields merged over the base payload.
     * @return mixed[]
     */
    public function build(Order $order, string $eventType, array $extra = []): array
    {
        $items = [];
        foreach ($order->getAllVisibleItems() as $item) {
            $items[] = [
                'sku'        => $item->getSku(),
                'name'       => $item->getName(),
                'qty'        => (float) $item->getQtyOrdered(),
                'price'      => (float) $item->getPrice(),
                'row_total'  => (float) $item->getRowTotal(),
                'product_id' => (int) $item->getProductId(),
            ];
        }

        $billing  = $order->getBillingAddress();
        $shipping = $order->getShippingAddress();

        $payload = [
            'event_type'          => $eventType,
            'store_id'            => $this->api->getStoreId(),
            'increment_id'        => $order->getIncrementId(),
            'grand_total'         => (float) $order->getGrandTotal(),
            'subtotal'            => (float) $order->getSubtotal(),
            'customer_email'      => $order->getCustomerEmail(),
            'customer_firstname'  => $order->getCustomerFirstname(),
            'customer_lastname'   => $order->getCustomerLastname(),
            'customer_is_guest'   => (bool) $order->getCustomerIsGuest(),
            'customer_id'         => $order->getCustomerId() !== null
                                        ? (int) $order->getCustomerId()
                                        : null,
            'state'               => $order->getState(),
            'status'              => $order->getStatus(),
            'items'               => $items,
            'billing_address'     => $billing  ? $billing->getData()  : null,
            'shipping_address'    => $shipping ? $shipping->getData() : null,
            'payment'             => $order->getPayment()
                                        ? ['method' => $order->getPayment()->getMethod()]
                                        : null,
            'currency_code'       => $order->getOrderCurrencyCode(),
            'created_at'          => $order->getCreatedAt(),
            'updated_at'          => $order->getUpdatedAt(),
        ];

        return $extra === [] ? $payload : array_merge($payload, $extra);
    }

    /**
     * Same store + same increment_id always derives the same key, so a re-fire of
     * the underlying Magento event (admin save, capture, etc.) is de-duped by the
     * receiver. Scoped per event type so "placed" and "paid" for one order are not
     * mistaken for duplicates of each other.
     */
    public function idempotencyKey(Order $order, string $suffix): string
    {
        return sprintf(
            'magento-order-%s%d-%s',
            $suffix === '' ? '' : $suffix . '-',
            (int) $order->getStoreId(),
            (string) $order->getIncrementId()
        );
    }
}
