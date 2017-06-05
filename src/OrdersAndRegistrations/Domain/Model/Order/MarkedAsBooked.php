<?php
declare(strict_types=1);

namespace OrdersAndRegistrations\Domain\Model\Order;

final class MarkedAsBooked
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
