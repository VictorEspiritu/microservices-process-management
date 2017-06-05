<?php
declare(strict_types=1);

namespace OrdersAndRegistrations\Application\ConferenceManagement;

use OrdersAndRegistrations\Domain\Model\Order\ConferenceId;

final class ConferenceCreated
{
    /**
     * @var ConferenceId
     */
    private $conferenceId;

    /**
     * @var int
     */
    private $availableTickets;

    public function __construct(ConferenceId $conferenceId, int $availableTickets)
    {
        $this->conferenceId = $conferenceId;
        $this->availableTickets = $availableTickets;
    }

    public function conferenceId(): ConferenceId
    {
        return $this->conferenceId;
    }

    public function availableTickets(): int
    {
        return $this->availableTickets;
    }
}
