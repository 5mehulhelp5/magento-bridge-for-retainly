<?php

declare(strict_types=1);

namespace Retnly\MagentoBridge\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Retnly\MagentoBridge\Helper\Api;
use Retnly\MagentoBridge\Model\EventOutbox;
use Retnly\MagentoBridge\Model\OrderPayloadBuilder;

class OrderFulfilledObserver implements ObserverInterface
{
    private Api $api;
    private EventOutbox $outbox;
    private OrderPayloadBuilder $payloadBuilder;

    public function __construct(Api $api, EventOutbox $outbox, OrderPayloadBuilder $payloadBuilder)
    {
        $this->api            = $api;
        $this->outbox         = $outbox;
        $this->payloadBuilder = $payloadBuilder;
    }

    public function execute(Observer $observer): void
    {
        if (!$this->api->isEnabled()) {
            return;
        }

        /** @var \Magento\Sales\Model\Order\Shipment $shipment */
        $shipment = $observer->getEvent()->getShipment();
        /** @var \Magento\Sales\Model\Order $order */
        $order = $shipment->getOrder();

        // Fire only once the LAST item ships — a partial shipment is not fulfilment.
        //
        // Do NOT test $order->getState() === STATE_COMPLETE here, however obvious it
        // looks. This event fires DURING shipment creation, before Magento persists
        // the order's transition to `complete`, so the state still reads `processing`
        // and the check silently swallows every fulfilment. (Verified on a live store:
        // order reached `complete`, shipment existed, and no event was ever enqueued.)
        //
        // getQtyToShip() is the reliable question: Shipment::register() has already
        // decremented it for the shipment being saved, so it reads 0 on exactly the
        // shipment that completes the order.
        foreach ($order->getAllVisibleItems() as $item) {
            if ($item->getQtyToShip() > 0) {
                return;
            }
        }

        $this->outbox->enqueue(
            'orders/',
            $this->payloadBuilder->build($order, 'magento_order_fulfilled'),
            $this->payloadBuilder->idempotencyKey($order, 'fulfilled')
        );
    }
}
