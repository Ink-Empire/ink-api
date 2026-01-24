<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;

class SubscriptionController extends Controller
{
    /**
     * Subscribe user to updates via signed URL from email
     */
    public function subscribe(Request $request)
    {
        // Validate the signed URL
        if (!$request->hasValidSignature()) {
            return redirect(config('app.frontend_url') . '/updates?error=invalid_link');
        }

        $userId = $request->query('user');

        if (!$userId) {
            return redirect(config('app.frontend_url') . '/updates?error=invalid_link');
        }

        $user = User::find($userId);

        if (!$user) {
            return redirect(config('app.frontend_url') . '/updates?error=user_not_found');
        }

        // Subscribe the user
        $user->update(['is_subscribed' => true]);

        // Redirect to frontend with success message
        return redirect(config('app.frontend_url') . '/updates?subscribed=true');
    }
}
