<?php

namespace App\Http\Controllers;

use App\Enums\UserTypes;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AddressService;
use App\Services\UserService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{

    public function __construct(
        protected UserService $userService,
        protected AddressService $addressService
    )
    {
    }
    /**
     * Register a new user and return a token
     */
    public function register(Request $request): \Illuminate\Http\JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8|confirmed',
            'username' => [
                'required',
                'string',
                'max:30',
                'unique:users',
                'regex:/^[a-zA-Z0-9._]+$/' // Only letters, numbers, periods, and underscores
            ],
            'slug' => 'required|string|max:30|unique:users',
            //'device_name' => 'required|string',
        ]);

        if (isset($request->address)) {
            $address = $this->addressService->create(
                $this->addressService->mapFields($request->address)
            );
        }

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'username' => $request->username,
            'slug' => $request->slug,
            'password' => Hash::make($request->password),
            'phone' => $request->phone ?? null,
            'location' => $request->location ?? null,
            'location_lat_long' => $request->location_lat_long ?? null,
            'type_id' => $request->type == UserTypes::USER ? 1 : 2,
            'address_id' => $address->id ?? null
        ]);

        // For SPA authentication, log the user in using sessions
        auth()->login($user);

        return response()->json([
            'user' => new UserResource($user),
            'message' => 'User registered and logged in successfully'
        ], 201);
    }

    public function checkUsername(Request $request)
    {
        $request->validate([
            'username' => [
                'required',
                'string',
                'max:30',
                'unique:users',
                'regex:/^[a-zA-Z0-9._]+$/' // Only letters, numbers, periods, and underscores
            ],
        ]);

        return response()->json(['message' => 'Username is available'], 200);
    }

    /**
     * Login and return a token
     */
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required'
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        // For SPA authentication, use Laravel's built-in session authentication
        auth()->login($user);

        return response()->json([
            'user' => new UserResource($user),
            'message' => 'Logged in successfully'
        ]);
    }

    /**
     * Logout and revoke token
     */
    public function logout(Request $request)
    {
        // Check if user is authenticated before attempting logout
        if (auth('web')->check()) {
            auth('web')->logout();
        }
        
        // Always invalidate session and regenerate token for security
        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json(['message' => 'Logged out successfully']);
    }

    /**
     * Get the authenticated user
     */
    public function me(Request $request)
    {
        return new UserResource($request->user());
    }
}
