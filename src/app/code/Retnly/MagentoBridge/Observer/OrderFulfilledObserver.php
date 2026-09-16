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

        // Only fire when the order has actually reached the complete state.
        // An order can have partial shipments while still in processing state.
        if ($order->getState() !== \Magento\Sales\Model\Order::STATE_COMPLETE) {
            return;
        }

        $this->outbox->enqueue(
            'orders/',
            $this->payloadBuilder->build($order, 'magento_order_fulfilled'),
            $this->payloadBuilder->idempotencyKey($order, 'fulfilled')
        );
    }
}
