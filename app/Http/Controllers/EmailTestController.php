<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Appointment;
use App\Models\TattooLead;
use App\Notifications\BookingAcceptedNotification;
use App\Notifications\BookingDeclinedNotification;
use App\Notifications\BookingRequestNotification;
use App\Notifications\BooksOpenNotification;
use App\Notifications\ResetPasswordNotification;
use App\Notifications\TattooBeaconNotification;
use App\Notifications\VerifyEmailNotification;
use App\Notifications\WelcomeNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;

class EmailTestController extends Controller
{
    public function getTypes()
    {
        return response()->json([
            'types' => [
                ['id' => 'welcome', 'name' => 'Welcome Email'],
                ['id' => 'verify-email', 'name' => 'Verify Email'],
                ['id' => 'password-reset', 'name' => 'Password Reset'],
                ['id' => 'booking-request', 'name' => 'Booking Request'],
                ['id' => 'booking-accepted', 'name' => 'Booking Accepted'],
                ['id' => 'booking-declined', 'name' => 'Booking Declined'],
                ['id' => 'books-open', 'name' => 'Books Open'],
                ['id' => 'tattoo-beacon', 'name' => 'Tattoo Beacon'],
            ],
        ]);
    }

    public function send(Request $request)
    {
        $request->validate([
            'type' => 'required|string|in:welcome,verify-email,password-reset,booking-request,booking-accepted,booking-declined,books-open,tattoo-beacon',
            'email' => 'required|email',
        ]);

        $type = $request->input('type');
        $email = $request->input('email');

        try {
            $notification = $this->createNotification($type);

            // Use Laravel's built-in on-demand notification routing
            Notification::route('mail', $email)->notify($notification);

            return response()->json([
                'success' => true,
                'message' => "Test email '{$type}' sent to {$email}",
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to send email: ' . $e->getMessage(),
            ], 500);
        }
    }

    private function createNotification(string $type)
    {
        return match ($type) {
            'welcome' => new WelcomeNotification(),
            'verify-email' => new VerifyEmailNotification(),
            'password-reset' => new ResetPasswordNotification($this->generateTestToken()),
            'booking-request' => new BookingRequestNotification($this->getTestAppointment()),
            'booking-accepted' => new BookingAcceptedNotification($this->getTestAppointment()),
            'booking-declined' => new BookingDeclinedNotification($this->getTestAppointment()),
            'books-open' => new BooksOpenNotification($this->getTestArtist()),
            'tattoo-beacon' => new TattooBeaconNotification($this->getTestLead(), $this->getTestClient()),
            default => throw new \InvalidArgumentException("Unknown email type: {$type}"),
        };
    }

    private function generateTestToken(): string
    {
        return 'test-token-' . bin2hex(random_bytes(16));
    }

    private function getTestAppointment(): Appointment
    {
        // Try to get an existing appointment, or create mock data
        $appointment = Appointment::with(['client', 'artist'])->first();

        if (!$appointment) {
            // Create a mock appointment for testing
            $appointment = new Appointment();
            $appointment->id = 0;
            $appointment->type = 'appointment';
            $appointment->date = now()->addWeek();
            $appointment->start_time = '14:00';
            $appointment->end_time = '16:00';
            $appointment->notes = 'This is a test appointment for email preview purposes.';

            // Create mock client relationship
            $client = User::first();
            if ($client) {
                $appointment->setRelation('client', $client);
            }
        }

        return $appointment;
    }

    private function getTestArtist(): User
    {
        // Get an artist user for testing
        $artist = User::where('type_id', 2)->first();

        if (!$artist) {
            $artist = User::first();
        }

        return $artist;
    }

    private function getTestClient(): User
    {
        // Get a client user for testing
        $client = User::where('type_id', 1)->first();

        if (!$client) {
            $client = User::first();
        }

        return $client;
    }

    private function getTestLead(): TattooLead
    {
        $lead = TattooLead::first();

        if (!$lead) {
            // Create a mock lead for testing
            $lead = new TattooLead();
            $lead->id = 0;
            $lead->description = 'This is a test tattoo lead for email preview purposes.';
            $lead->timing = 'flexible';
        }

        return $lead;
    }
}
