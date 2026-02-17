<?php

namespace App\Http\Controllers;

use App\Models\User;

class SupportController extends Controller
{
    public function getContact()
    {
        $user = User::where('email', 'info@getinked.in')->first();

        return response()->json(['user_id' => $user?->id]);
    }
}
