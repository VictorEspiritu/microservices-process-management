<?php
declare(strict_types=1);

namespace OrdersAndRegistrations\Application;

use Common\Persistence\Database;
use OrdersAndRegistrations\Application;
use OrdersAndRegistrations\Domain\Model\Order\OrderExpired;
use OrdersAndRegistrations\Domain\Model\Order\OrderPlaced;
use OrdersAndRegistrations\Domain\Model\SeatsAvailability\ReservationAccepted;
use OrdersAndRegistrations\Domain\Model\SeatsAvailability\ReservationRejected;

final class OrderProcessManager
{
    /**
     * @var Application
     */
    private $application;

    public function __construct(Application $application)
    {
        $this->application = $application;
    }

    public function whenOrderPlaced(OrderPlaced $event): void
    {
        /*
         * Transition to state: AwaitingReservationConfirmation
         */
        $state = OrderProcessState::awaitReservationConfirmation(
            $event->conferenceId(),
            $event->orderId()
        );
        $this->saveState($state);

        /*
         * Send command: MakeSeatReservation
         */
        $command = new MakeSeatReservation();
        $command->conferenceId = (string)$event->conferenceId();
        $command->reservationId = (string)$event->orderId();
        $command->numberOfSeats = $event->numberOfTickets();

        $this->application->makeSeatReservation($command);
    }

    public function whenReservationAccepted(ReservationAccepted $event): void
    {
        $orderProcessState = $this->loadState(
            (string)$event->reservationId()
        );

        /*
         * Transition to state: AwaitingPayment
         */
        $orderProcessState->awaitPayment();
        Database::persist($orderProcessState);

        /*
         * Send command: MarkAsBooked
         */
        $command = new MarkAsBooked();
        $command->orderId = (string)$event->reservationId();
        $this->application->markAsBooked($command);

        /*
         * Send delayed command: ExpireOrder (in 15 minutes)
         */
        // TODO
    }

    public function whenReservationRejected(ReservationRejected $event): void
    {
        $state = $this->loadState(
            (string)$event->reservationId()
        );

        /*
         * Transition to state: Rejected
         */
        $state->reject();
        $this->saveState($state);

        /*
         * Send command: RejectOrder
         */
        $command = new RejectOrder();
        $command->orderId = (string)$event->reservationId();
        $this->application->rejectOrder($command);
    }

    public function whenPaymentReceived(PaymentReceived $event): void
    {
        $state = $this->loadState(
            (string)$event->orderId()
        );

        /*
         * Transition to state: Completed
         */
        $state->complete();
        $this->saveState($state);

        /*
         * Send command: CommitSeatReservation
         */
        $command = new CommitSeatReservation();
        $command->reservationId = (string)$event->orderId();
        $command->conferenceId = (string)$state->conferenceId();

        $this->application->commitSeatReservation($command);
    }

    public function whenOrderExpired(OrderExpired $event): void
    {
        $state = $this->loadState(
            (string)$event->orderId()
        );

        $state->expire();
        $this->saveState($state);
    }

    private function loadState(string $id): OrderProcessState
    {
        return Database::retrieve(
            OrderProcessState::class,
            $id
        );
    }

    private function saveState(OrderProcessState $state): void
    {
        Database::persist($state);
    }
}
