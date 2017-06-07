<?php
declare(strict_types=1);

namespace OrdersAndRegistrations;

use function Common\CommandLine\line;
use function Common\CommandLine\make_green;
use function Common\CommandLine\make_red;
use function Common\CommandLine\make_yellow;
use function Common\CommandLine\stdout;
use Common\EventDispatcher\EventDispatcher;
use Common\EventSourcing\Aggregate\Repository\EventSourcedAggregateRepository;
use Common\EventSourcing\EventStore\EventStore;
use Common\EventSourcing\EventStore\Storage\DatabaseStorageFacility;
use NaiveSerializer\JsonSerializer;
use OrdersAndRegistrations\Application\BadRequest;
use OrdersAndRegistrations\Application\CancelSeatReservation;
use OrdersAndRegistrations\Application\CommitSeatReservation;
use OrdersAndRegistrations\Application\ConferenceManagement\ConferenceCreated;
use OrdersAndRegistrations\Application\ExpireOrder;
use OrdersAndRegistrations\Application\MakeSeatReservation;
use OrdersAndRegistrations\Application\MarkAsBooked;
use OrdersAndRegistrations\Application\OrderProcessManager;
use OrdersAndRegistrations\Application\Payment\PaymentReceived;
use OrdersAndRegistrations\Application\PlaceOrder;
use OrdersAndRegistrations\Application\RejectOrder;
use OrdersAndRegistrations\Domain\Model\Order\ConferenceId;
use OrdersAndRegistrations\Domain\Model\Order\MarkedAsBooked;
use OrdersAndRegistrations\Domain\Model\Order\Order;
use OrdersAndRegistrations\Domain\Model\Order\OrderExpired;
use OrdersAndRegistrations\Domain\Model\Order\OrderId;
use OrdersAndRegistrations\Domain\Model\Order\OrderPlaced;
use OrdersAndRegistrations\Domain\Model\Order\OrderRejected;
use OrdersAndRegistrations\Domain\Model\SeatsAvailability\ReservationAccepted;
use OrdersAndRegistrations\Domain\Model\SeatsAvailability\ReservationCancelled;
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

    public function markAsBooked(MarkAsBooked $command): void
    {
        /** @var Order $order */
        $order = $this->orderRepository()->getById($command->orderId);

        $order->markAsBooked();

        $this->orderRepository()->save($order);
    }

    public function makeSeatReservation(MakeSeatReservation $command): void
    {
        /** @var SeatsAvailability $seatsAvailability */
        $seatsAvailability = $this->seatsAvailabilityRepository()->getById($command->conferenceId);

        $seatsAvailability->makeReservation(ReservationId::fromString($command->reservationId), $command->numberOfSeats);

        $this->seatsAvailabilityRepository()->save($seatsAvailability);
    }

    public function whenOrderPlaced(): void
    {
        stdout(line('Now send out an email confirming the order...'));
    }

    public function whenReservationCommitted(): void
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
                if ($event instanceof MarkedAsBooked) {
                    stdout(make_yellow(sprintf('Order marked as booked (%s)', (string)$event->orderId())));
                }
                if ($event instanceof OrderExpired) {
                    stdout(make_red(sprintf('Order expired (%s)', (string)$event->orderId())));
                }
                if ($event instanceof OrderPlaced) {
                    stdout(make_yellow(sprintf('Order placed (%s)', (string)$event->orderId())));
                }
                if ($event instanceof OrderRejected) {
                    stdout(make_red(line('Order rejected (', (string)$event->orderId(), ')')));
                }
                if ($event instanceof ReservationAccepted) {
                    stdout(make_yellow(line('Reservation accepted (', (string)$event->reservationId(), ')')));
                }
                if ($event instanceof ReservationCancelled) {
                    stdout(make_red(line('Reservation cancelled (', (string)$event->reservationId(), ')')));
                }
                if ($event instanceof ReservationCommitted) {
                    stdout(make_green(line('Reservation committed (', (string)$event->reservationId(), ')')));
                }
                if ($event instanceof ReservationRejected) {
                    stdout(make_red(line('Reservation rejected (', (string)$event->reservationId(), ')')));
                }
                if ($event instanceof PaymentReceived) {
                    stdout(make_yellow(line('Payment received (', (string)$event->orderId(), ')')));
                }
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
                OrderExpired::class,
                [$this->orderProcessManager(), 'whenOrderExpired']
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

    public function consumePaymentSucceededMessage(string $rawMessage): void
    {
        $data = json_decode($rawMessage);
        if (!isset($data->correlationId)) {
            throw new BadRequest('Expected JSON data to contain a "correlationId" field');
        }

        $event = new PaymentReceived(OrderId::fromString($data->correlationId));

        $this->eventDispatcher()->dispatch($event);
    }

    public function commitSeatReservation(CommitSeatReservation $command): void
    {
        /** @var SeatsAvailability $seatsAvailability */
        $seatsAvailability = $this->seatsAvailabilityRepository()->getById($command->conferenceId);

        $seatsAvailability->commitReservation(ReservationId::fromString($command->reservationId));

        $this->seatsAvailabilityRepository()->save($seatsAvailability);
    }

    public function cancelSeatReservation(CancelSeatReservation $command): void
    {
        /** @var SeatsAvailability $seatsAvailability */
        $seatsAvailability = $this->seatsAvailabilityRepository()->getById($command->conferenceId);

        $seatsAvailability->cancelReservation(ReservationId::fromString($command->reservationId));

        $this->seatsAvailabilityRepository()->save($seatsAvailability);
    }
}
