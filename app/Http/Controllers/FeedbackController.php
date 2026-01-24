<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class FeedbackController extends Controller
{
    public function submit(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'feedback' => 'required|string|min:10|max:2000',
        ]);

        $email = $request->input('email');
        $feedback = $request->input('feedback');

        try {
            Mail::raw($feedback, function ($message) use ($email) {
                $message->to('info@getinked.in')
                    ->replyTo($email)
                    ->subject("Feedback Form - {$email}");
            });

            Log::info('Feedback submitted', ['email' => $email]);

            return response()->json([
                'success' => true,
                'message' => 'Thank you for your feedback!',
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to send feedback', [
                'email' => $email,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to send feedback. Please try again.',
            ], 500);
        }
    }
}
