<?php

declare(strict_types=1);

namespace Retnly\MagentoBridge\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Retnly\MagentoBridge\Helper\Api;
use Retnly\MagentoBridge\Model\EventOutbox;
use Retnly\MagentoBridge\Model\OrderPayloadBuilder;

/**
 * Sends magento_order_cancelled.
 *
 * Registered on `order_cancel_after`, which is what Magento\Sales\Model\Order::cancel()
 * dispatches. Note this is NOT `sales_order_cancel_after` — that name appears in older
 * internal notes but no such event is dispatched by Magento 2, which is part of why this
 * trigger was listed in the dashboard for months while never firing.
 */
class OrderCancelledObserver implements ObserverInterface
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

        /** @var \Magento\Sales\Model\Order $order */
        $order = $observer->getEvent()->getOrder();
        if ($order === null) {
            return;
        }

        $this->outbox->enqueue(
            'orders/',
            $this->payloadBuilder->build($order, 'magento_order_cancelled'),
            $this->payloadBuilder->idempotencyKey($order, 'cancelled')
        );
    }
}
