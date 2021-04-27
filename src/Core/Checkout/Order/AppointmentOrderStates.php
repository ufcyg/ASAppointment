<?php

declare(strict_types=1);

namespace ASAppointment\Core\Checkout\Order;

final class AppointmentOrderStates
{
    public const STATE_MACHINE = 'order.state';
    public const STATE_APPOINTED = 'appointed';
    public const STATE_APPOINTMENT_CANCELLED = 'cancelledAppointment';
}
