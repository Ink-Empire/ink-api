<?php

namespace App\Http\Controllers;

use App\Enums\AppointmentStatus;
use App\Http\Resources\AppointmentResource;
use App\Http\Resources\RawAppointmentResource;
use App\Models\Appointment;
use App\Models\Artist;
use App\Models\User;
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

    public function store(Request $request)
    {
        $artist_id = $request->get('artist_id');
        if (!$artist_id) {
            return response()->json(['error' => 'Artist ID is required'], 400);
        }

        $artist = Artist::find($artist_id);

        $data = $request->validate([
            'title' => 'required|string',
            'start_time' => 'required',
            'end_time' => 'required',
            'all_day' => 'boolean',
            'description' => 'string',
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
        //TODO add an event here that will email both artist and client

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
            'artist_id' => 'required|exists:artists,id',
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
}
