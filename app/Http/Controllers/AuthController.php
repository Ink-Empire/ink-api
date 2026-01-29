<?php

namespace App\Http\Controllers;

use App\Actions\Fortify\PasswordValidationRules;
use App\Enums\UserTypes;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\UserResource;
use App\Http\Resources\SelfUserResource;
use App\Models\User;
use App\Models\Studio;
use App\Services\AddressService;
use App\Services\UserService;
use Illuminate\Auth\Events\Registered;
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
            'type_id' => UserTypes::getTypeId($request->type ?? UserTypes::USER),
            'address_id' => $address->id ?? null,
            'experience_level' => $request->experience_level ?? null,
            'studio_id' => $request->studio_id ?? null, // Studio affiliation for artists
        ]);

        // Store password in history
        $user->passwords()->create([
            'password' => $hashedPassword,
        ]);

        // If artist affiliated with a studio, add them to the studio's artists (pending verification)
        if ($request->studio_id) {
            $studio = Studio::find($request->studio_id);
            if ($studio) {
                // Add artist to users_studios pivot with is_verified = false (pending)
                // initiated_by = 'artist' means the artist requested to join
                $studio->artists()->syncWithoutDetaching([
                    $user->id => [
                        'is_verified' => false,
                        'initiated_by' => 'artist',
                    ]
                ]);

                // Notify the studio owner about the join request
                if ($studio->owner) {
                    try {
                        $studio->owner->notify(new \App\Notifications\ArtistJoinRequestNotification($user, $studio));
                    } catch (\Exception $e) {
                        Log::warning('Failed to send artist join request notification', [
                            'artist_id' => $user->id,
                            'studio_id' => $studio->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }
        }

        // Save selected styles if provided
        if ($request->has('selected_styles') && is_array($request->selected_styles)) {
            $user->styles()->sync($request->selected_styles);
        }

        // Fire Registered event - Laravel's listener will send verification email
        // (Welcome email is sent after verification in VerifyEmailController)
        event(new Registered($user));

        // Create a temporary token for profile image upload
        // User still needs to verify email before normal login
        $token = $user->createToken('registration-upload')->plainTextToken;

        return response()->json([
            'message' => 'Registration successful. Please check your email to verify your account.',
            'requires_verification' => true,
            'email' => $user->email,
            'user' => ['id' => $user->id],
            'token' => $token,
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

        // Check if email is verified
        if (!$user->hasVerifiedEmail()) {
            // Resend verification email
            $user->sendEmailVerificationNotification();

            return response()->json([
                'message' => 'Please verify your email address before logging in.',
                'requires_verification' => true,
                'email' => $user->email,
            ], 403);
        }

        // Update last login timestamp
        $user->update(['last_login_at' => now()]);

        // Delete old tokens and create a new one for API authentication
        $user->tokens()->delete();
        $token = $user->createToken('auth-token')->plainTextToken;

        return response()->json([
            'user' => new SelfUserResource($user),
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
