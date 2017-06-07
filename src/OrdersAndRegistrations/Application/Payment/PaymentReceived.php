<?php
declare(strict_types=1);

namespace OrdersAndRegistrations\Application\Payment;

use OrdersAndRegistrations\Domain\Model\Order\OrderId;

final class PaymentReceived
{
    /**
     * @var OrderId
     */
    private $orderId;

    public function __construct(OrderId $orderId)
    {
        $this->orderId = $orderId;
    }

    public function orderId(): OrderId
    {
        return $this->orderId;
    }
}
