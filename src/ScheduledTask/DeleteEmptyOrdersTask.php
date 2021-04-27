<?php

declare(strict_types=1);

namespace ASAppointment\ScheduledTask;

use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTask;

class DeleteEmptyOrdersTask extends ScheduledTask
{
    public static function getTaskName(): string
    {
        return 'as.delete_empty_orders_task';
    }

    public static function getDefaultInterval(): int
    {
        return 240; // every 5 minutes
    }
}
