<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\VerifyEmailController;
use App\Http\Controllers\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Auth\UpdatePasswordController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\StyleController;
use App\Http\Controllers\CountryController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\AppointmentController;
use App\Http\Controllers\StudioController;
use App\Http\Controllers\TagController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

// Public routes
Route::get('/styles', [StyleController::class, 'index']);
Route::get('/countries', [CountryController::class, 'index']);
Route::post('/studios/check-availability', [StudioController::class, 'checkAvailability']);

// Tag routes (public for autocomplete)
Route::get('/tags', [TagController::class, 'index']);
Route::get('/tags/search', [TagController::class, 'search']);
Route::get('/tags/featured', [TagController::class, 'featured']);

// Google Places config (returns API key for frontend SDK use)
Route::get('/places/config', [\App\Http\Controllers\PlacesController::class, 'config']);

// Auth routes
Route::post('/register', [AuthController::class, 'register']);
Route::post('/username', [AuthController::class, 'checkUsername']);
Route::post('/check-availability', [AuthController::class, 'checkAvailability']);
Route::post('/login', [AuthController::class, 'login']);
Route::post('/logout', [AuthController::class, 'logout']);

// Password reset routes
Route::post('/forgot-password', [PasswordResetLinkController::class, 'store']);
Route::post('/reset-password', [NewPasswordController::class, 'store']);

// Email verification routes
Route::get('/verify-email/{id}/{hash}', VerifyEmailController::class)
    ->middleware(['signed', 'throttle:6,1'])
    ->name('verification.verify');
Route::post('/email/verification-notification', [EmailVerificationNotificationController::class, 'store'])
    ->middleware('throttle:6,1');

// Protected routes
Route::middleware('auth:sanctum')->group(function () {
    Route::prefix('users')->group(function () {
        Route::get('/me', [UserController::class, 'me']);
        Route::put('/password', [UpdatePasswordController::class, 'update']);
    });

    // Tattoo tag management (authenticated)
    Route::post('/tattoos/{tattooId}/tags', [TagController::class, 'setTattooTags']);
    Route::post('/tattoos/{tattooId}/tags/add', [TagController::class, 'addTattooTag']);

    // Appointment routes
    Route::prefix('appointments')->group(function () {
        Route::post('/inbox', [AppointmentController::class, 'inbox']);
        Route::post('/history', [AppointmentController::class, 'history']);
        Route::put('/{id}', [AppointmentController::class, 'update']);
    });

    // Message routes
    Route::prefix('messages')->group(function () {
        Route::get('/unread-count', [MessageController::class, 'getUnreadCount']);
        Route::get('/inbox', [MessageController::class, 'getInboxThreads']);
        Route::get('/appointment/{appointmentId}', [MessageController::class, 'getMessages']);
        Route::post('/send', [MessageController::class, 'sendMessage']);
        Route::put('/{messageId}/read', [MessageController::class, 'markAsRead']);
    });
});
