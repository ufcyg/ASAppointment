<?php declare(strict_types=1);

namespace ASAppointment\ScheduledTask;

use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTask;

class UpdateProductsTask extends ScheduledTask
{
    public static function getTaskName(): string
    {
        return 'as.update_products_task';
    }

    public static function getDefaultInterval(): int
    {
        return 30; // 1 minutes
    }
}