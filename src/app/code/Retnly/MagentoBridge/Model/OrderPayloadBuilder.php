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
                // `qty` is kept for older receivers; `qty_ordered` is what the Retnly
                // parser looks for first.
                'qty'              => (float) $item->getQtyOrdered(),
                'qty_ordered'      => (float) $item->getQtyOrdered(),
                'price'            => (float) $item->getPrice(),
                'row_total'        => (float) $item->getRowTotal(),
                'product_id'       => (int) $item->getProductId(),
                // Per-line tax and discount. Their absence is why every Magento
                // receipt recorded zero tax and zero discount: the parser reads
                // these keys, and nothing was sending them.
                'tax_amount'       => (float) $item->getTaxAmount(),
                'tax_percent'      => (float) $item->getTaxPercent(),
                'discount_amount'  => (float) $item->getDiscountAmount(),
                'row_total_incl_tax' => $item->getRowTotalInclTax() !== null
                                            ? (float) $item->getRowTotalInclTax()
                                            : null,
                'product_type'     => $item->getProductType(),
                // Fulfilment state per line — lets a consumer tell a partly shipped
                // or partly refunded order from a complete one without re-querying.
                'qty_invoiced'     => (float) $item->getQtyInvoiced(),
                'qty_shipped'      => (float) $item->getQtyShipped(),
                'qty_refunded'     => (float) $item->getQtyRefunded(),
                'qty_canceled'     => (float) $item->getQtyCanceled(),
            ];
        }

        $billing  = $order->getBillingAddress();
        $shipping = $order->getShippingAddress();

        $payload = [
            'event_type'          => $eventType,
            'store_id'            => $this->api->getStoreId(),
            'increment_id'        => $order->getIncrementId(),
            // Magento's own numeric PK. The receiver files this as the invoice ref;
            // without it that field was written blank on every receipt.
            'entity_id'           => $order->getEntityId() !== null
                                        ? (int) $order->getEntityId()
                                        : null,
            'grand_total'         => (float) $order->getGrandTotal(),
            'subtotal'            => (float) $order->getSubtotal(),
            // --- Money the receiver already parses but was never sent -------------
            'tax_amount'          => (float) $order->getTaxAmount(),
            'discount_amount'     => (float) $order->getDiscountAmount(),
            'discount_description' => $order->getDiscountDescription(),
            'coupon_code'         => $order->getCouponCode(),
            'shipping_amount'     => (float) $order->getShippingAmount(),
            'shipping_incl_tax'   => $order->getShippingInclTax() !== null
                                        ? (float) $order->getShippingInclTax()
                                        : null,
            'shipping_tax_amount' => (float) $order->getShippingTaxAmount(),
            'shipping_method'     => $order->getShippingMethod(),
            'shipping_description' => $order->getShippingDescription(),
            'subtotal_incl_tax'   => $order->getSubtotalInclTax() !== null
                                        ? (float) $order->getSubtotalInclTax()
                                        : null,
            // --- Running totals: how much of this order is actually settled -------
            'total_qty_ordered'   => (float) $order->getTotalQtyOrdered(),
            'total_invoiced'      => (float) $order->getTotalInvoiced(),
            'total_paid'          => (float) $order->getTotalPaid(),
            'total_refunded'      => (float) $order->getTotalRefunded(),
            'total_due'           => (float) $order->getTotalDue(),
            // --- Who and where ----------------------------------------------------
            'customer_group_id'   => $order->getCustomerGroupId() !== null
                                        ? (int) $order->getCustomerGroupId()
                                        : null,
            // Fallback identity: a virtual/downloadable order has no billing address,
            // so the receiver reads this key when billing_address.telephone is absent.
            'customer_telephone'  => $billing ? $billing->getTelephone() : null,
            'customer_note'       => $order->getCustomerNote(),
            'store_name'          => $order->getStoreName(),
            'magento_store_id'    => (int) $order->getStoreId(),
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
