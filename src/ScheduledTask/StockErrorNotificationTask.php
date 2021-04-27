<?php

declare(strict_types=1);

namespace ASAppointment\ScheduledTask;

use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTask;

class StockErrorNotificationTask extends ScheduledTask
{
    public static function getTaskName(): string
    {
        return 'as.stock_error_notification_task';
    }

    public static function getDefaultInterval(): int
    {
        return 86340; // 24 hours
    }
}
