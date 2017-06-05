<?php
declare(strict_types=1);

namespace OrdersAndRegistrations\Domain\Model\Order;

use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

class OrderTest extends TestCase
{
    /**
     * @test
     */
    public function an_order_can_be_placed()
    {
        $orderId = OrderId::fromString((string)Uuid::uuid4());
        $conferenceId = ConferenceId::fromString((string)Uuid::uuid4());
        $numberOfTickets = 2;

        $order = \OrdersAndRegistrations\Domain\Model\Order\Order::place($orderId, $conferenceId, $numberOfTickets);

        $this->assertEquals(
            [
                new OrderPlaced(
                    $orderId,
                    $conferenceId,
                    $numberOfTickets
                )
            ],
            $order->popRecordedEvents()
        );
    }
}
