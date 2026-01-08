<?php

namespace App\Observers;

use App\Models\Appointment;
use App\Jobs\SyncAppointmentToGoogle;

class AppointmentObserver
{
    /**
     * Handle the Appointment "created" event.
     */
    public function created(Appointment $appointment): void
    {
        // Only sync if status is booked
        if ($appointment->status === 'booked') {
            SyncAppointmentToGoogle::dispatch($appointment->id, 'upsert');
        }
    }

    /**
     * Handle the Appointment "updated" event.
     */
    public function updated(Appointment $appointment): void
    {
        // Only sync if relevant fields changed
        $relevantFields = ['date', 'start_time', 'end_time', 'status', 'title', 'description'];

        if (!$appointment->wasChanged($relevantFields)) {
            return;
        }

        // If cancelled, delete from Google Calendar
        if ($appointment->status === 'cancelled') {
            if ($appointment->google_event_id) {
                SyncAppointmentToGoogle::dispatch($appointment->id, 'delete');
            }
            return;
        }

        // If status changed to booked, or other relevant fields changed
        if ($appointment->status === 'booked') {
            SyncAppointmentToGoogle::dispatch($appointment->id, 'upsert');
        }
    }

    /**
     * Handle the Appointment "deleted" event.
     */
    public function deleted(Appointment $appointment): void
    {
        if ($appointment->google_event_id) {
            SyncAppointmentToGoogle::dispatch($appointment->id, 'delete');
        }
    }
}
