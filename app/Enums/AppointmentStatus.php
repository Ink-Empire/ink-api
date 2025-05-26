<?php

namespace App\Enums;

class AppointmentStatus
{
    //booked, completed, cancelled
    const PENDING = 'pending';
    const BOOKED = 'booked';
    const COMPLETED = 'completed';
    const CANCELLED = 'cancelled';
}
