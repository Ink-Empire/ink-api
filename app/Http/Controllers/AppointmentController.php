<?php

namespace App\Http\Controllers;

use App\Enums\AppointmentStatus;
use App\Http\Resources\AppointmentResource;
use App\Models\Artist;
use Illuminate\Http\Request;

class AppointmentController extends Controller
{
    public function index(Request $request)
    {
        $artist_id = $request->get('artist_id');
        $status = $request->get('status');

        if (!$artist_id) {
            return response()->json(['error' => 'Artist ID or slug is required'], 400);
        }

        //if artist_id is not a number, its a slug
        if (!is_numeric($artist_id)) {
            $artist = Artist::where('slug', $artist_id)->first();
        } else {
            $artist = Artist::find($artist_id);
        }

        if (!$artist) {
            return response()->json(['error' => 'Artist not found'], 404);
        }

        $appointments = $this->getAppointmentsByStatus($artist, $status);

        return AppointmentResource::collection($appointments);
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

    private function getAppointmentsByStatus($artist, $status = AppointmentStatus::BOOKED)
    {
        return $artist->appointments()
//            ->with('tattoo')
            ->with('client')
            ->with('artist')
            ->where('status', '=', $status)
            ->get();
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
            'start' => 'required|date',
            'end' => 'required|date',
            'all_day' => 'boolean',
            'description' => 'string',
            'type' => 'required|string|in:tattoo,consultation',
        ]);


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

        $data = $request->validate([
            'title' => 'string',
            'start' => 'date',
            'end' => 'date',
            'all_day' => 'boolean',
            'description' => 'string',
            'type' => 'string|in:tattoo,consultation',
        ]);

        $appointment->update($data);

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
}
