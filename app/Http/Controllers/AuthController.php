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
            'has_accepted_toc' => 'required|accepted',
            'has_accepted_privacy_policy' => 'required|accepted',
            'signup_platform' => 'nullable|string|in:web,ios,android',
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
            'has_accepted_toc' => true,
            'has_accepted_privacy_policy' => true,
            'signup_platform' => $request->signup_platform ?? null,
        ]);

        // Store password in history
        $user->passwords()->create([
            'password' => $hashedPassword,
        ]);

        // If artist affiliated with a studio, create a pending join request
        // Artists never auto-claim studios — only the studio registration flow does that
        if ($request->studio_id) {
            $studio = Studio::find($request->studio_id);
            if ($studio) {
                $studio->artists()->syncWithoutDetaching([
                    $user->id => [
                        'is_verified' => false,
                        'initiated_by' => 'artist',
                    ]
                ]);

                // Notify the studio owner about the join request (if studio is claimed)
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

        // If registering as a studio owner, create or claim the studio
        if (($request->type ?? '') === 'studio') {
            if ($request->claim_studio_id) {
                // Claiming an existing unclaimed studio
                $studio = Studio::find($request->claim_studio_id);
                if ($studio && !$studio->is_claimed) {
                    $studio->update([
                        'owner_id' => $user->id,
                        'is_claimed' => true,
                        'about' => $request->about ?? $studio->about,
                        'phone' => $request->studio_phone ?? $studio->phone,
                        'email' => $request->studio_email ?? $studio->email,
                    ]);
                }
            } else {
                // Create a new studio
                $studio = Studio::create([
                    'name' => $request->name,
                    'slug' => $request->slug,
                    'about' => $request->about ?? null,
                    'location' => $request->location ?? null,
                    'location_lat_long' => $request->location_lat_long ?? null,
                    'email' => $request->studio_email ?? null,
                    'phone' => $request->studio_phone ?? null,
                    'owner_id' => $user->id,
                    'is_claimed' => true,
                ]);
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
        $token = $user->createToken('registration-upload', ['*'], now()->addMinutes(30))->plainTextToken;

        $response = [
            'message' => 'Registration successful. Please check your email to verify your account.',
            'requires_verification' => true,
            'email' => $user->email,
            'user' => ['id' => $user->id],
            'token' => $token,
        ];

        if (isset($studio)) {
            $response['studio'] = ['id' => $studio->id];
        }

        return response()->json($response, 201);
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

        $identifier = $request->email;
        $query = User::with([
            'ownedStudio',
            'verifiedStudios.image',
            'blockedUsers.image',
            'styles',
            'socialMediaLinks',
            'artists',
            'tattoos',
            'studios',
            'type',
            'image',
        ]);

        if (str_contains($identifier, '@')) {
            $user = $query->where('email', $identifier)->first();
        } else {
            $user = $query->where('username', $identifier)->first();
        }

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

        // Create a new token for API authentication
        // Note: We no longer delete all tokens on login to allow multiple devices/sessions
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
