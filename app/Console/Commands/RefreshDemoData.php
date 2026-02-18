<?php

namespace App\Console\Commands;

use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Models\Appointment;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Console\Command;

class RefreshDemoData extends Command
{
    protected $signature = 'demo:refresh-dates
                           {--dry-run : Show what would change without modifying data}
                           {--force : Skip confirmation prompt}';

    protected $description = 'Shift demo appointment dates and message timestamps forward so demo data always appears current';

    public function handle(): int
    {
        $demoUserIds = User::where('is_demo', true)->pluck('id');

        if ($demoUserIds->isEmpty()) {
            $this->warn('No demo users found.');
            return 1;
        }

        $this->info("Found {$demoUserIds->count()} demo users.");

        // Find all demo appointments (artist or client is a demo user)
        $demoAppointments = Appointment::where(function ($q) use ($demoUserIds) {
            $q->whereIn('artist_id', $demoUserIds)
              ->orWhereIn('client_id', $demoUserIds);
        })->get();

        if ($demoAppointments->isEmpty()) {
            $this->warn('No demo appointments found.');
            return 1;
        }

        $this->info("Found {$demoAppointments->count()} demo appointments.");

        // Find earliest booked appointment to anchor the offset
        $earliestBooked = $demoAppointments
            ->where('status', 'booked')
            ->min('date');

        if (!$earliestBooked) {
            $this->warn('No booked demo appointments found.');
            return 1;
        }

        $earliestBookedDate = Carbon::parse($earliestBooked);
        $targetDate = today()->addDays(3);
        $offsetDays = $earliestBookedDate->diffInDays($targetDate, false);

        if (abs($offsetDays) < 2) {
            $this->info('Demo dates are already current (offset is only ' . $offsetDays . ' days). No changes needed.');
            return 0;
        }

        $this->info("Earliest booked appointment: {$earliestBookedDate->toDateString()}");
        $this->info("Target date: {$targetDate->toDateString()}");
        $this->info("Offset: {$offsetDays} days");

        // Find conversations/messages involving demo users
        $demoConversationIds = ConversationParticipant::whereIn('user_id', $demoUserIds)
            ->pluck('conversation_id')
            ->unique();

        $messageCount = Message::whereIn('conversation_id', $demoConversationIds)->count();
        $participantCount = ConversationParticipant::whereIn('conversation_id', $demoConversationIds)->count();

        $this->newLine();
        $this->info('Summary of changes:');
        $this->line("  Appointments: {$demoAppointments->count()}");
        $this->line("  Conversations: {$demoConversationIds->count()}");
        $this->line("  Messages: {$messageCount}");
        $this->line("  Participants: {$participantCount}");

        if ($this->option('dry-run')) {
            $this->newLine();
            $this->info('[DRY RUN] No changes made.');
            return 0;
        }

        if (!$this->option('force') && !$this->confirm('Proceed with date shift?')) {
            $this->info('Cancelled.');
            return 0;
        }

        // Shift appointment dates
        $weekendShifts = 0;
        foreach ($demoAppointments as $appointment) {
            $newDate = Carbon::parse($appointment->date)->addDays($offsetDays);

            // Skip weekends: shift Saturday to Monday, Sunday to Monday
            if ($newDate->isSaturday()) {
                $newDate->addDays(2);
                $weekendShifts++;
            } elseif ($newDate->isSunday()) {
                $newDate->addDay();
                $weekendShifts++;
            }

            $appointment->timestamps = false;
            $appointment->date = $newDate->toDateString();
            $appointment->save();
        }

        $this->line("  Shifted {$demoAppointments->count()} appointment dates ({$weekendShifts} moved off weekends).");

        // Shift message timestamps
        Message::whereIn('conversation_id', $demoConversationIds)
            ->update([
                'created_at' => \DB::raw("DATE_ADD(created_at, INTERVAL {$offsetDays} DAY)"),
                'updated_at' => \DB::raw("DATE_ADD(updated_at, INTERVAL {$offsetDays} DAY)"),
            ]);

        $this->line("  Shifted {$messageCount} message timestamps.");

        // Shift conversation timestamps
        Conversation::whereIn('id', $demoConversationIds)
            ->update([
                'created_at' => \DB::raw("DATE_ADD(created_at, INTERVAL {$offsetDays} DAY)"),
                'updated_at' => \DB::raw("DATE_ADD(updated_at, INTERVAL {$offsetDays} DAY)"),
            ]);

        $this->line("  Shifted {$demoConversationIds->count()} conversation timestamps.");

        // Shift participant last_read_at
        ConversationParticipant::whereIn('conversation_id', $demoConversationIds)
            ->whereNotNull('last_read_at')
            ->update([
                'last_read_at' => \DB::raw("DATE_ADD(last_read_at, INTERVAL {$offsetDays} DAY)"),
            ]);

        $this->line("  Shifted participant last_read_at timestamps.");

        $this->newLine();
        $this->info('Demo data dates refreshed successfully.');

        return 0;
    }
}
