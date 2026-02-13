<?php

namespace App\Http\Controllers;

use App\Models\DeviceToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeviceTokenController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'token' => 'required|string|max:512',
            'platform' => 'sometimes|string|in:ios,android',
            'device_id' => 'nullable|string|max:255',
        ]);

        $user = $request->user();
        $data = [
            'user_id' => $user->id,
            'token' => $request->token,
            'platform' => $request->input('platform', 'ios'),
        ];

        if ($request->device_id) {
            // Upsert by device_id — one token per physical device per user
            DeviceToken::updateOrCreate(
                ['user_id' => $user->id, 'device_id' => $request->device_id],
                $data
            );
        } else {
            // Upsert by token
            DeviceToken::updateOrCreate(
                ['token' => $request->token],
                $data
            );
        }

        return response()->json(['message' => 'Device token registered']);
    }

    public function destroy(Request $request): JsonResponse
    {
        $request->validate([
            'token' => 'required|string|max:512',
        ]);

        DeviceToken::where('user_id', $request->user()->id)
            ->where('token', $request->token)
            ->delete();

        return response()->json(['message' => 'Device token unregistered']);
    }
}
