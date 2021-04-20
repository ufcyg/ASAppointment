<?php declare(strict_types=1);

namespace ASAppointment\ScheduledTask;

use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;


class AppointmentOrderTaskHandler extends ScheduledTaskHandler
{    
    public function __construct(EntityRepositoryInterface $scheduledTaskRepository)
    {
        parent::__construct($scheduledTaskRepository);
    }

    public static function getHandledMessages(): iterable
    {
        return [ AppointmentOrderTask::class ];
    }

    public function run(): void
    {
        
    }    
}