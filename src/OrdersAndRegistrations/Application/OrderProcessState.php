<?php
declare(strict_types=1);

namespace OrdersAndRegistrations\Application;

use OrdersAndRegistrations\Domain\Model\Order\ConferenceId;
use OrdersAndRegistrations\Domain\Model\Order\OrderId;

final class OrderProcessState
{
    const AWAITING_RESERVATION_CONFIRMATION = 'AWAITING_RESERVATION_CONFIRMATION';
    const AWAITING_PAYMENT = 'AWAITING_PAYMENT';
    const REJECTED = 'REJECTED';
    const COMPLETED = 'COMPLETED';
    const EXPIRED = 'EXPIRED';

    /**
     * @var OrderId
     */
    private $orderId;

    /**
     * @var ConferenceId
     */
    private $conferenceId;

    /**
     * @var string
     */
    private $state;

    public function id(): string
    {
        return (string)$this->orderId;
    }

    public static function awaitReservationConfirmation(ConferenceId $conferenceId, OrderId $orderId): OrderProcessState
    {
        $orderState = new OrderProcessState();

        $orderState->conferenceId = $conferenceId;
        $orderState->orderId = $orderId;
        $orderState->state = self::AWAITING_RESERVATION_CONFIRMATION;

        return $orderState;
    }

    public function awaitPayment(): void
    {
        if ($this->state !== self::AWAITING_RESERVATION_CONFIRMATION) {
            throw new InvalidOperation();
        }

        $this->state = self::AWAITING_PAYMENT;
    }

    public function complete(): void
    {
        if ($this->state !== self::AWAITING_PAYMENT) {
            throw new InvalidOperation();
        }

        $this->state = self::COMPLETED;
    }

    public function reject(): void
    {
        if ($this->state !== self::AWAITING_RESERVATION_CONFIRMATION) {
            throw new InvalidOperation();
        }

        $this->state = self::REJECTED;
    }

    public function expire(): void
    {
        if ($this->state !== self::AWAITING_PAYMENT) {
            throw new InvalidOperation();
        }

        $this->state = self::EXPIRED;
    }

    public function conferenceId(): ConferenceId
    {
        return $this->conferenceId;
    }
}
