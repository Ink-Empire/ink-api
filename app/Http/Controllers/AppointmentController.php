<?php

namespace App\Http\Controllers;

use App\Enums\AppointmentStatus;
use App\Http\Resources\AppointmentResource;
use App\Http\Resources\RawAppointmentResource;
use App\Jobs\SyncAppointmentToGoogle;
use App\Models\Appointment;
use App\Models\Artist;
use App\Models\CalendarConnection;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Notifications\BookingRequestNotification;
use App\Notifications\BookingAcceptedNotification;
use App\Services\ConversationService;
use App\Util\ModelLookup;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AppointmentController extends Controller
{
    public function index(Request $request)
    {
        $user_id = $request->get('user_id');
        $status = $request->get('status');

        if (!$user_id) {
            return response()->json(['error' => 'User ID or slug is required'], 400);
        }

        $user = ModelLookup::findUser($user_id);

        $appointments = $user->appointmentsWithStatus([$status])
            ->with('messages')
            ->get();

        return AppointmentResource::collection($appointments);
    }

    public function inbox(Request $request)
    {
        $user_id = $request->get('user_id');
        $status = $request->get('status', AppointmentStatus::PENDING);

        if (!$user_id) {
            return response()->json(['error' => 'User ID is required'], 400);
        }

        $user = ModelLookup::findUser($user_id);

        if (!$user) {
            return response()->json(['error' => 'User not found'], 404);
        }

        // Only show appointments where the user has unread messages as a recipient
        $appointments = $user->appointmentsWithStatus([$status])
            ->with('messages')
            ->with(['client', 'artist'])
            ->whereHas('messages', function($query) use ($user_id) {
                $query->where('recipient_id', $user_id)
                      ->whereNull('read_at');
            })
            ->get();

        return RawAppointmentResource::collection($appointments);
    }

    public function history(Request $request)
    {
        $user_id = $request->get('user_id');
        $page = $request->get('page', 1);
        $perPage = 25;

        if (!$user_id) {
            return response()->json(['error' => 'User ID is required'], 400);
        }

        $user = ModelLookup::findUser($user_id);

        if (!$user) {
            return response()->json(['error' => 'User not found'], 404);
        }

        // Get all non-pending appointments (history) with pagination
//        $appointments = $artist->appointments()
//            ->with('client')
//            ->with('artist')
//            ->with('messages')
//            ->whereIn('status', [AppointmentStatus::BOOKED, AppointmentStatus::COMPLETED, AppointmentStatus::CANCELLED])
//            ->orderBy('created_at', 'desc')
//            ->paginate($perPage, ['*'], 'page', $page);

        $appointments = $user->appointmentsWithStatus([AppointmentStatus::BOOKED, AppointmentStatus::COMPLETED, AppointmentStatus::CANCELLED])
            ->with('client')
            ->with('artist')
            ->with('messages')
            ->orderBy('created_at', 'desc')
            ->paginate($perPage, ['*'], 'page', $page);

        return RawAppointmentResource::collection($appointments);
    }

    public function getById($artist_id, $id)
    {
        $artist = Artist::find($artist_id);
        if (!$artist) {
            return response()->json(['error' => 'Artist not found'], 404);
        }

        $appointment = $artist->appointments()->with('tattoo')->with('user')->with('artist')->find($id);
        if (!$appointment) {
            return response()->json(['error' => 'Appointment not found'], 404);
        }

        return new AppointmentResource($appointment);
    }

    public function store(Request $request, ConversationService $conversationService)
    {
        $artist_id = $request->get('artist_id');
        if (!$artist_id) {
            return response()->json(['error' => 'Artist ID is required'], 400);
        }

        $artist = Artist::find($artist_id);

        if (!$artist) {
            return response()->json(['error' => 'Artist not found'], 404);
        }

        // Check if either user has blocked the other
        $user = $request->user();
        if ($user->hasBlocked($artist_id) || $user->isBlockedBy($artist_id)) {
            return response()->json(['error' => 'Cannot book with this artist'], 403);
        }

        $data = $request->validate([
            'title' => 'required|string',
            'start_time' => 'required',
            'end_time' => 'required',
            'all_day' => 'boolean',
            'description' => 'nullable|string',
            'type' => 'required|string|in:tattoo,consultation',
            'client_id' => 'required|exists:users,id',
            'date' => 'required|date',
        ]);

        $data['status'] = AppointmentStatus::PENDING;
        //date should be in the format YYYY-MM-DD
        $data['date'] = date('Y-m-d', strtotime($request->get('date')));
        //start_time and end_time should be in the format HH:MM:SS
        $data['start_time'] = date('H:i:s', strtotime($request->get('start_time')));
        $data['end_time'] = date('H:i:s', strtotime($request->get('end_time')));

        $appointment = $artist->appointments()->create($data);

        // Find existing conversation between client and artist, or create one
        $conversation = $conversationService->findOrCreate(
            $data['client_id'],
            $artist->id,
            $data['type'],
            $appointment->id
        );

        // Always link the new appointment to the conversation
        if ($conversation->appointment_id !== $appointment->id) {
            $conversation->update(['appointment_id' => $appointment->id]);
        }

        // Create initial message from the client
        $typeLabel = $data['type'] === 'consultation' ? 'consultation' : 'appointment';
        $messageContent = "New {$typeLabel} request";
        if (!empty($data['description'])) {
            $messageContent .= ": " . $data['description'];
        }

        Message::create([
            'conversation_id' => $conversation->id,
            'appointment_id' => $appointment->id,
            'sender_id' => $data['client_id'],
            'recipient_id' => $artist->id,
            'content' => $messageContent,
            'message_type' => 'initial',
            'type' => 'booking_card',
            'metadata' => [
                'appointment_id' => $appointment->id,
                'type' => $data['type'],
                'date' => $data['date'],
                'start_time' => $data['start_time'],
                'end_time' => $data['end_time'],
            ],
        ]);

        // Send email notification to the artist
        \Log::info('Dispatching booking notification', ['artist_id' => $artist->id, 'appointment_id' => $appointment->id]);
        try {
            $appointment->load('client'); // Load client relationship for notification
            $artist->notify(new BookingRequestNotification($appointment));
            \Log::info('Booking notification dispatched');
        } catch (\Exception $e) {
            \Log::error('Failed to send booking notification: ' . $e->getMessage());
        }

        // Sync to Google Calendar if artist has it connected (creates tentative event)
        try {
            $connection = CalendarConnection::where('user_id', $artist->id)
                ->where('provider', 'google')
                ->where('sync_enabled', true)
                ->first();

            if ($connection) {
                SyncAppointmentToGoogle::dispatch($appointment->id, 'create');
            }
        } catch (\Exception $e) {
            \Log::error('Failed to dispatch Google Calendar sync: ' . $e->getMessage());
        }

        return new AppointmentResource($appointment);
    }

    public function update(Request $request, $id)
    {
        $appointment = Appointment::find($id);
        if (!$appointment) {
            return response()->json(['error' => 'Appointment not found'], 404);
        }

        $appointmentFields = $appointment->getFillable();

        foreach ($appointmentFields as $field) {
            if ($request->has($field)) {
                $appointment->{$field} = $request->get($field);
            }
        }

        $appointment->save();

        return new AppointmentResource($appointment);
    }

    public function respondToRequest(Request $request, $id)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $data = $request->validate([
            'action' => 'required|string|in:accept,decline',
            'reason' => 'nullable|string|max:500',
        ]);

        $appointment = Appointment::with(['client', 'artist'])->find($id);
        if (!$appointment) {
            return response()->json(['error' => 'Appointment not found'], 404);
        }

        // Verify the user is the artist for this appointment
        if ($appointment->artist_id !== $user->id) {
            return response()->json(['error' => 'You can only respond to your own appointments'], 403);
        }

        // Check appointment is in pending status
        if ($appointment->status !== AppointmentStatus::PENDING) {
            return response()->json(['error' => 'Appointment is not pending'], 400);
        }

        $action = $data['action'];

        if ($action === 'accept') {
            $appointment->status = AppointmentStatus::BOOKED;
            $appointment->save();

            // Sync to Google Calendar if artist has it connected
            try {
                $connection = CalendarConnection::where('user_id', $user->id)
                    ->where('provider', 'google')
                    ->where('sync_enabled', true)
                    ->first();

                if ($connection) {
                    SyncAppointmentToGoogle::dispatch($appointment->id, 'update');
                    \Log::info('Dispatched Google Calendar sync for accepted appointment', ['appointment_id' => $appointment->id]);
                }
            } catch (\Exception $e) {
                \Log::error('Failed to dispatch Google Calendar sync for accepted appointment: ' . $e->getMessage());
            }

            // Send email notification to the client
            try {
                if ($appointment->client) {
                    $appointment->client->notify(new BookingAcceptedNotification($appointment));
                    \Log::info('Sent booking accepted notification to client', ['client_id' => $appointment->client_id]);
                }
            } catch (\Exception $e) {
                \Log::error('Failed to send booking accepted notification: ' . $e->getMessage());
            }

            // Add a system message to the conversation
            $conversation = Conversation::where('appointment_id', $appointment->id)->first();
            if ($conversation) {
                Message::create([
                    'conversation_id' => $conversation->id,
                    'appointment_id' => $appointment->id,
                    'sender_id' => $user->id,
                    'recipient_id' => $appointment->client_id,
                    'content' => 'Booking request accepted',
                    'type' => 'system',
                ]);
            }

            return response()->json([
                'message' => 'Appointment accepted successfully',
                'appointment' => new AppointmentResource($appointment),
            ]);
        } else {
            // Decline
            $appointment->status = AppointmentStatus::CANCELLED;
            $appointment->save();

            // Remove from Google Calendar if it was synced
            try {
                $connection = CalendarConnection::where('user_id', $user->id)
                    ->where('provider', 'google')
                    ->where('sync_enabled', true)
                    ->first();

                if ($connection && $appointment->google_event_id) {
                    SyncAppointmentToGoogle::dispatch($appointment->id, 'delete');
                    \Log::info('Dispatched Google Calendar delete for declined appointment', ['appointment_id' => $appointment->id]);
                }
            } catch (\Exception $e) {
                \Log::error('Failed to dispatch Google Calendar delete for declined appointment: ' . $e->getMessage());
            }

            // Send email notification to the client
            try {
                if ($appointment->client) {
                    $appointment->client->notify(new \App\Notifications\BookingDeclinedNotification($appointment, $data['reason'] ?? null));
                    \Log::info('Sent booking declined notification to client', ['client_id' => $appointment->client_id]);
                }
            } catch (\Exception $e) {
                \Log::error('Failed to send booking declined notification: ' . $e->getMessage());
            }

            // Add a system message to the conversation
            $conversation = Conversation::where('appointment_id', $appointment->id)->first();
            if ($conversation) {
                $content = 'Booking request declined';
                if (!empty($data['reason'])) {
                    $content .= ': ' . $data['reason'];
                }
                Message::create([
                    'conversation_id' => $conversation->id,
                    'appointment_id' => $appointment->id,
                    'sender_id' => $user->id,
                    'recipient_id' => $appointment->client_id,
                    'content' => $content,
                    'type' => 'system',
                ]);
            }

            return response()->json([
                'message' => 'Appointment declined',
                'appointment' => new AppointmentResource($appointment),
            ]);
        }
    }

    public function delete($id)
    {
        $appointment = Appointment::find($id);
        if (!$appointment) {
            return response()->json(['error' => 'Appointment not found'], 404);
        }

        $appointment->delete();

        return response()->json(['message' => 'Appointment deleted successfully'], 200);
    }

    public function invite(Request $request)
    {
        $data = $request->validate([
            'artist_id' => 'required|exists:users,id',
            'date' => 'required|date',
            'type' => 'required|string|in:consultation,appointment',
            'guest_email' => 'required|email',
            'guest_name' => 'nullable|string',
            'note' => 'nullable|string',
        ]);

        $artist = Artist::find($data['artist_id']);
        if (!$artist) {
            return response()->json(['error' => 'Artist not found'], 404);
        }

        // Find or create the guest user by email
        $guest = User::where('email', $data['guest_email'])->first();

        if (!$guest) {
            // Create a new user with a temporary password
            $guest = User::create([
                'email' => $data['guest_email'],
                'name' => $data['guest_name'] ?? explode('@', $data['guest_email'])[0],
                'password' => bcrypt(Str::random(16)),
                'type_id' => 1, // Client type
            ]);
        }

        // Create the appointment
        $appointment = $artist->appointments()->create([
            'title' => $data['type'] === 'consultation' ? 'Consultation' : 'Tattoo Appointment',
            'date' => date('Y-m-d', strtotime($data['date'])),
            'start_time' => '09:00:00', // Default start time
            'end_time' => $data['type'] === 'consultation' ? '09:30:00' : '12:00:00', // Default end times
            'type' => $data['type'] === 'consultation' ? 'consultation' : 'tattoo',
            'status' => AppointmentStatus::PENDING,
            'client_id' => $guest->id,
            'description' => $data['note'] ?? null,
        ]);

        // TODO: Send email notification to guest

        return response()->json([
            'message' => 'Invite sent successfully',
            'appointment' => new AppointmentResource($appointment),
        ], 201);
    }

    /**
     * Create a calendar event for the artist (personal/blocking time)
     * Optionally syncs to Google Calendar
     */
    public function createEvent(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $data = $request->validate([
            'artist_id' => 'required|exists:users,id',
            'title' => 'nullable|string|max:255',
            'type' => 'required|string|in:consultation,appointment,other',
            'start' => 'required|date',
            'end' => 'required|date|after:start',
            'description' => 'nullable|string',
            'status' => 'nullable|string',
            'sync_to_google' => 'nullable|boolean',
        ]);

        // Verify the user owns this artist profile
        // Artist extends User, so artist->id IS the user's id
        $artist = Artist::find($data['artist_id']);
        if (!$artist || $artist->id !== $user->id) {
            return response()->json(['error' => 'You can only create events for your own calendar'], 403);
        }

        // Parse start and end datetimes
        $start = new \DateTime($data['start']);
        $end = new \DateTime($data['end']);

        // Generate title if not provided
        $title = $data['title'] ?? match($data['type']) {
            'consultation' => 'Consultation',
            'appointment' => 'Appointment',
            'other' => 'Busy',
        };

        // Create the appointment/event
        $appointment = $artist->appointments()->create([
            'title' => $title,
            'date' => $start->format('Y-m-d'),
            'start_time' => $start->format('H:i:s'),
            'end_time' => $end->format('H:i:s'),
            'type' => $data['type'] === 'other' ? 'other' : $data['type'],
            'status' => $data['status'] ?? AppointmentStatus::BOOKED,
            'description' => $data['description'] ?? null,
            'client_id' => null, // Personal event, no client
        ]);

        // Sync to Google Calendar if requested
        if ($request->boolean('sync_to_google')) {
            $connection = CalendarConnection::where('user_id', $user->id)
                ->where('provider', 'google')
                ->where('sync_enabled', true)
                ->first();

            if ($connection) {
                SyncAppointmentToGoogle::dispatch($appointment->id, 'create');
            }
        }

        return response()->json([
            'message' => 'Event created successfully',
            'appointment' => new AppointmentResource($appointment),
        ], 201);
    }

    /**
     * Get all appointments for an artist (for calendar display)
     * Includes both client appointments and personal events
     */
    public function getArtistAppointments(Request $request)
    {
        $data = $request->validate([
            'artist_id' => 'required',
            'status' => 'nullable|string',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date',
        ]);

        // Handle both numeric ID and slug
        $artistId = $data['artist_id'];
        if (!is_numeric($artistId)) {
            // It's a slug, find the artist
            $artist = Artist::where('slug', $artistId)->first();
            if (!$artist) {
                return response()->json(['error' => 'Artist not found'], 404);
            }
            $artistId = $artist->id;
        }

        $query = Appointment::where('artist_id', $artistId)
            ->with(['client', 'artist', 'studio']);

        // Filter by status if provided
        if (!empty($data['status']) && $data['status'] !== 'all') {
            $query->where('status', $data['status']);
        }

        // Filter by date range if provided
        if (!empty($data['start_date'])) {
            $query->where('date', '>=', $data['start_date']);
        }
        if (!empty($data['end_date'])) {
            $query->where('date', '<=', $data['end_date']);
        }

        $appointments = $query->orderBy('date', 'asc')
            ->orderBy('start_time', 'asc')
            ->get();

        return AppointmentResource::collection($appointments);
    }
}
