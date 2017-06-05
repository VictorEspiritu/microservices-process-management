<?php
declare(strict_types=1);

namespace OrdersAndRegistrations;

use function Common\CommandLine\line;
use function Common\CommandLine\make_green;
use function Common\CommandLine\stdout;
use Common\EventDispatcher\EventDispatcher;
use Common\EventSourcing\Aggregate\Repository\EventSourcedAggregateRepository;
use Common\EventSourcing\EventStore\EventStore;
use Common\EventSourcing\EventStore\Storage\DatabaseStorageFacility;
use NaiveSerializer\JsonSerializer;
use OrdersAndRegistrations\Application\CommitSeatReservation;
use OrdersAndRegistrations\Application\ConferenceManagement\ConferenceCreated;
use OrdersAndRegistrations\Application\ExpireOrder;
use OrdersAndRegistrations\Application\MakeSeatReservation;
use OrdersAndRegistrations\Application\MarkAsBooked;
use OrdersAndRegistrations\Application\OrderProcessManager;
use OrdersAndRegistrations\Application\PaymentReceived;
use OrdersAndRegistrations\Application\PlaceOrder;
use OrdersAndRegistrations\Application\RejectOrder;
use OrdersAndRegistrations\Domain\Model\Order\ConferenceId;
use OrdersAndRegistrations\Domain\Model\Order\Order;
use OrdersAndRegistrations\Domain\Model\Order\OrderId;
use OrdersAndRegistrations\Domain\Model\Order\OrderPlaced;
use OrdersAndRegistrations\Domain\Model\SeatsAvailability\ReservationAccepted;
use OrdersAndRegistrations\Domain\Model\SeatsAvailability\ReservationCommitted;
use OrdersAndRegistrations\Domain\Model\SeatsAvailability\ReservationId;
use OrdersAndRegistrations\Domain\Model\SeatsAvailability\ReservationRejected;
use OrdersAndRegistrations\Domain\Model\SeatsAvailability\SeatsAvailability;

final class Application
{
    public function placeOrder(PlaceOrder $command): void
    {
        $order = Order::place(
            OrderId::fromString($command->orderId),
            ConferenceId::fromString($command->conferenceId),
            (int)$command->numberOfTickets
        );

        $this->orderRepository()->save($order);
    }

    public function rejectOrder(RejectOrder $command): void
    {
        /** @var Order $order */
        $order = $this->orderRepository()->getById($command->orderId);

        $order->reject();

        $this->orderRepository()->save($order);
    }

    public function expireOrder(ExpireOrder $command): void
    {
        /** @var Order $order */
        $order = $this->orderRepository()->getById($command->orderId);

        $order->expire();

        $this->orderRepository()->save($order);
    }

    public function markAsBooked(MarkAsBooked $command)
    {
        /** @var Order $order */
        $order = $this->orderRepository()->getById($command->orderId);

        $order->markAsBooked();

        $this->orderRepository()->save($order);
    }

    public function makeReservation(MakeSeatReservation $command): void
    {
        /** @var SeatsAvailability $seatsAvailability */
        $seatsAvailability = $this->seatsAvailabilityRepository()->getById($command->conferenceId);

        $seatsAvailability->makeReservation(ReservationId::fromString($command->reservationId), $command->quantity);

        $this->seatsAvailabilityRepository()->save($seatsAvailability);
    }

    public function whenOrderPlaced(OrderPlaced $event)
    {
        stdout(line('Now send out an email confirming the order...'));
    }

    public function whenReservationCommitted(ReservationCommitted $event)
    {
        stdout(line('We can now start creating and sending the invoice, etc...'));
    }

    private function orderRepository(): EventSourcedAggregateRepository
    {
        static $orderRepository;

        if ($orderRepository === null) {
            $orderRepository = $orderRepository ?? new EventSourcedAggregateRepository(
                    new EventStore(
                        new DatabaseStorageFacility(),
                        $this->eventDispatcher(),
                        new JsonSerializer()
                    ),
                    Order::class
                );
        }

        return $orderRepository;
    }

    private function eventDispatcher(): EventDispatcher
    {
        static $eventDispatcher;

        if ($eventDispatcher === null) {
            $eventDispatcher = new EventDispatcher();

            $eventDispatcher->subscribeToAllEvents(function ($event) {
                stdout(line(make_green('Received an event')));
                dump($event);
            });

            $eventDispatcher->registerSubscriber(
                ConferenceCreated::class,
                [$this, 'whenConferenceCreated']
            );
            $eventDispatcher->registerSubscriber(
                OrderPlaced::class,
                [$this, 'whenOrderPlaced']
            );

            $eventDispatcher->registerSubscriber(
                OrderPlaced::class,
                [$this->orderProcessManager(), 'whenOrderPlaced']
            );
            $eventDispatcher->registerSubscriber(
                ReservationAccepted::class,
                [$this->orderProcessManager(), 'whenReservationAccepted']
            );
            $eventDispatcher->registerSubscriber(
                ReservationRejected::class,
                [$this->orderProcessManager(), 'whenReservationRejected']
            );
            $eventDispatcher->registerSubscriber(
                PaymentReceived::class,
                [$this->orderProcessManager(), 'whenPaymentReceived']
            );
            $eventDispatcher->registerSubscriber(
                ReservationCommitted::class,
                [$this, 'whenReservationCommitted']
            );
        }

        return $eventDispatcher;
    }

    public function consumeConferenceCreatedEvent(string $rawEventData): void
    {
        $data = json_decode($rawEventData);

        $this->eventDispatcher()->dispatch(new ConferenceCreated(
            ConferenceId::fromString($data->id),
            $data->availableTickets
        ));
    }

    public function whenConferenceCreated(ConferenceCreated $event): void
    {
        // idempotent event subscriber

        try {
            $this->seatsAvailabilityRepository()->getById((string)$event->conferenceId());

            // SeatsAvailability aggregate already exists
            return;
        } catch (\RuntimeException $exception) {
            $seatsAvailability = SeatsAvailability::create($event->conferenceId(), $event->availableTickets());

            $this->seatsAvailabilityRepository()->save($seatsAvailability);
        }
    }

    private function seatsAvailabilityRepository(): EventSourcedAggregateRepository
    {
        static $seatsAvailabilityRepository;

        if ($seatsAvailabilityRepository === null) {
            $seatsAvailabilityRepository = $seatsAvailabilityRepository ?? new EventSourcedAggregateRepository(
                    new EventStore(
                        new DatabaseStorageFacility(),
                        $this->eventDispatcher(),
                        new JsonSerializer()
                    ),
                    SeatsAvailability::class
                );
        }

        return $seatsAvailabilityRepository;
    }

    private function orderProcessManager(): OrderProcessManager
    {
        static $orderProcessManager;

        if ($orderProcessManager === null) {
            $orderProcessManager = new OrderProcessManager($this);
        }

        return $orderProcessManager;
    }

    public function consumePaymentReceivedEvent(string $rawEventData): void
    {
        $data = json_decode($rawEventData);

        $this->eventDispatcher()->dispatch(new PaymentReceived(OrderId::fromString($data->orderId)));
    }

    public function commitSeatReservation(CommitSeatReservation $command): void
    {
        /** @var SeatsAvailability $seatsAvailability */
        $seatsAvailability = $this->seatsAvailabilityRepository()->getById($command->conferenceId);

        $seatsAvailability->commitReservation(ReservationId::fromString($command->reservationId));

        $this->seatsAvailabilityRepository()->save($seatsAvailability);
    }
}
