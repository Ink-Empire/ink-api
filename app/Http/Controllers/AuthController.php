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
            'about' => $request->about ?? null,
            'username' => $request->username,
            'slug' => $request->slug,
            'password' => Hash::make($request->password),
            'phone' => $request->phone ?? null,
            'location' => $request->location ?? null,
            'location_lat_long' => $request->location_lat_long ?? null,
            'type_id' => $request->type == UserTypes::USER ? 1 : 2,
            'address_id' => $address->id ?? null
        ]);

        // Create token for API authentication
        $token = $user->createToken('auth-token')->plainTextToken;

        return response()->json([
            'user' => new UserResource($user),
            'token' => $token,
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

        // Delete old tokens and create a new one for API authentication
        $user->tokens()->delete();
        $token = $user->createToken('auth-token')->plainTextToken;

        return response()->json([
            'user' => new UserResource($user),
            'token' => $token,
            'message' => 'Logged in successfully'
        ]);
    }

    /**
     * Logout and revoke token
     */
    public function logout(Request $request)
    {
        // Revoke the current access token
        if ($request->user()) {
            $request->user()->currentAccessToken()->delete();
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
