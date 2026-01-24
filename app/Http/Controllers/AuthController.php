<?php

namespace App\Http\Controllers;

use App\Actions\Fortify\PasswordValidationRules;
use App\Enums\UserTypes;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Models\Studio;
use App\Jobs\SendWelcomeNotification;
use App\Jobs\SendVerifyEmailNotification;
use App\Services\AddressService;
use App\Services\UserService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    use PasswordValidationRules;

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
            'password' => $this->passwordRules(),
            'username' => [
                'required',
                'string',
                'max:30',
                'unique:users',
                'regex:/^[a-zA-Z0-9._]+$/' // Only letters, numbers, periods, and underscores
            ],
            'slug' => 'required|string|max:30|unique:users',
            'studio_id' => 'nullable|integer|exists:studios,id', // Optional studio affiliation for artists
            'selected_styles.*' => 'integer|exists:styles,id',
        ]);

        if (isset($request->address)) {
            $address = $this->addressService->create(
                $this->addressService->mapFields($request->address)
            );
        }

        $hashedPassword = Hash::make($request->password);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'about' => $request->about ?? null,
            'username' => $request->username,
            'slug' => $request->slug,
            'password' => $hashedPassword,
            'phone' => $request->phone ?? null,
            'location' => $request->location ?? null,
            'location_lat_long' => $request->location_lat_long ?? null,
            'type_id' => $request->type == UserTypes::USER ? 1 : 2,
            'address_id' => $address->id ?? null,
            'experience_level' => $request->experience_level ?? null,
            'studio_id' => $request->studio_id ?? null, // Studio affiliation for artists
        ]);

        // Store password in history
        $user->passwords()->create([
            'password' => $hashedPassword,
        ]);

        // If artist affiliated with a studio, mark it as claimed
        if ($request->studio_id) {
            Studio::where('id', $request->studio_id)
                ->where('is_claimed', false)
                ->update(['is_claimed' => true]);
        }

        // Save selected styles if provided
        if ($request->has('selected_styles') && is_array($request->selected_styles)) {
            $user->styles()->sync($request->selected_styles);
        }

        \Log::info("Sending email verification via queued job to " . $user->id);
        // Queue verification email job (welcome email sent after verification)
        SendVerifyEmailNotification::dispatch($user->id);

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
     * Check if email or username is available
     */
    public function checkAvailability(Request $request)
    {
        $email = $request->input('email');
        $username = $request->input('username');

        // Check email availability
        if ($email) {
            $emailExists = User::where('email', $email)->exists();
            return response()->json([
                'available' => !$emailExists,
                'field' => 'email'
            ]);
        }

        // Check username availability
        if ($username) {
            // Validate username format first
            if (!preg_match('/^[a-zA-Z0-9._]+$/', $username)) {
                return response()->json([
                    'available' => false,
                    'field' => 'username',
                    'message' => 'Username can only contain letters, numbers, periods, and underscores'
                ]);
            }

            $usernameExists = User::where('username', $username)->exists();
            return response()->json([
                'available' => !$usernameExists,
                'field' => 'username'
            ]);
        }

        return response()->json([
            'available' => false,
            'message' => 'No email or username provided'
        ], 400);
    }

    /**
     * Login and return a token
     */
    public function login(LoginRequest $request)
    {
        // Check rate limiting
        $request->ensureIsNotRateLimited();

        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            RateLimiter::hit($request->throttleKey());

            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        RateLimiter::clear($request->throttleKey());

        // Update last login timestamp
        $user->update(['last_login_at' => now()]);

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
        // Revoke the current access token if it exists
        if ($request->user() && $request->user()->currentAccessToken()) {
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
