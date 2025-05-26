<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Get all existing appointments
        $appointments = DB::table('appointments')->get();

        foreach ($appointments as $appointment) {
            // Create an initial message for each appointment
            // The client is the sender for initial appointment requests
            DB::table('messages')->insert([
                'appointment_id' => $appointment->id,
                'sender_id' => $appointment->client_id,
                'recipient_id' => $appointment->artist_id,
                'content' => $this->generateInitialMessage($appointment),
                'message_type' => 'initial',
                'parent_message_id' => null,
                'read_at' => NULL,
                'created_at' => $appointment->created_at ?? Carbon::now(),
                'updated_at' => $appointment->updated_at ?? Carbon::now(),
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remove all initial messages created by this migration
        DB::table('messages')
            ->where('message_type', 'initial')
            ->delete();
    }

    /**
     * Generate an appropriate initial message based on appointment data
     */
    private function generateInitialMessage($appointment): string
    {
        $baseMessage = "Hi! I'd like to book";

        // Add appointment type
        if ($appointment->type === 'consultation') {
            $baseMessage .= " a consultation";
        } else {
            $baseMessage .= " an appointment";
        }

        // Add date if available
        if ($appointment->date) {
            $date = Carbon::parse($appointment->date)->format('M j, Y');
            $baseMessage .= " for {$date}";

            // Add time if not all day
            if (!$appointment->all_day && $appointment->start_time) {
                $time = Carbon::parse($appointment->start_time)->format('g:i A');
                $baseMessage .= " at {$time}";
            }
        }

        // Add description if available
        if ($appointment->description) {
            $baseMessage .= ".\n\nDetails: " . $appointment->description;
        } else {
            $baseMessage .= ". Please let me know if this works for you!";
        }

        return $baseMessage;
    }
};
