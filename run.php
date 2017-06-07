<?php
declare(strict_types=1);

use function Common\CommandLine\make_red;
use function Common\CommandLine\stdout;
use OrdersAndRegistrations\Application\ExpireOrder;
use OrdersAndRegistrations\Application\PlaceOrder;
use Ramsey\Uuid\Uuid;

require __DIR__ . '/bootstrap.php';

$application = new \OrdersAndRegistrations\Application();

$conferenceId = 'e5513420-1087-4aaf-82b3-202a124e3454';
/*
 * We consume an exterrnal event: conference created
 */
$externalConferenceCreatedEventData = <<<EOD
{
    "id": "$conferenceId",
    "name": "ServiceCon",
    "availableTickets": 100
}
EOD;
$application->consumeConferenceCreatedEvent($externalConferenceCreatedEventData);

$orderId = (string)Uuid::uuid4();
$command = new PlaceOrder();
$command->orderId = $orderId;
$command->conferenceId = $conferenceId;
$command->numberOfTickets = 2;

$application->placeOrder($command);

stdout(make_red('Payment received in time? y/n'));
$answer = fgets(STDIN);

if (substr(trim($answer), 0, 1) === 'y') {
    $externalPaymentReceivedEventData = <<<EOD
{
    "paidAmount": 395.50,
    "merchantId": "foo123",
    "correlationId": "$orderId"
}
EOD;

    $application->consumePaymentSucceededMessage($externalPaymentReceivedEventData);
} else {
    $command = new ExpireOrder();
    $command->orderId = $orderId;
    $application->expireOrder($command);
}
