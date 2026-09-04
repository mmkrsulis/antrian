<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap/app.php';

use App\Services\QueueService;

$serviceId = max(1, (int)($argv[1] ?? 1));
echo (new QueueService())->issue($serviceId)['ticket_number'], PHP_EOL;
