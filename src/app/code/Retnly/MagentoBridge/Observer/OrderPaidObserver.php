<?php

declare(strict_types=1);

namespace Retnly\MagentoBridge\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Retnly\MagentoBridge\Helper\Api;
use Retnly\MagentoBridge\Model\EventOutbox;
use Retnly\MagentoBridge\Model\OrderPayloadBuilder;

class OrderPaidObserver implements ObserverInterface
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

        /** @var \Magento\Sales\Model\Order\Invoice $invoice */
        $invoice = $observer->getEvent()->getInvoice();
        /** @var \Magento\Sales\Model\Order $order */
        $order = $invoice->getOrder();

        $this->outbox->enqueue(
            'orders/',
            $this->payloadBuilder->build($order, 'magento_order_paid'),
            $this->payloadBuilder->idempotencyKey($order, 'paid')
        );
    }
}
