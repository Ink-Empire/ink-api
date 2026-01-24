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
use App\Http\Controllers\PlacementController;
use App\Http\Controllers\BlockedTermController;
use App\Http\Controllers\CalendarOAuthController;
use App\Http\Controllers\CalendarWebhookController;
use App\Http\Controllers\TattooLeadController;
use App\Http\Controllers\EmailTestController;

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
Route::post('/studios/lookup-or-create', [StudioController::class, 'lookupOrCreate']);
Route::post('/studios/{id}/claim', [StudioController::class, 'claim']);

// Tag routes (public for autocomplete)
Route::get('/tags', [TagController::class, 'index']);
Route::get('/tags/search', [TagController::class, 'search']);
Route::get('/tags/featured', [TagController::class, 'featured']);

// Placement routes (public)
Route::get('/placements', [PlacementController::class, 'index']);

// Artist appointments (public for calendar display)
Route::post('/artists/appointments', [AppointmentController::class, 'getArtistAppointments']);

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
        Route::post('/favorites/{type}', [UserController::class, 'toggleFavorite']);
    });

    // Direct S3 upload routes (presigned URLs)
    Route::prefix('uploads')->group(function () {
        Route::post('/presign', [\App\Http\Controllers\ImageController::class, 'getPresignedUrl']);
        Route::post('/presign-batch', [\App\Http\Controllers\ImageController::class, 'getPresignedUrls']);
        Route::post('/confirm', [\App\Http\Controllers\ImageController::class, 'confirmUploads']);
    });

    // Tag management (authenticated)
    Route::post('/tags', [TagController::class, 'create']);
    Route::post('/tags/suggest', [TagController::class, 'suggestFromImages']);
    Route::post('/tags/create-from-ai', [TagController::class, 'createFromAiSuggestion']);
    Route::post('/tattoos/{tattooId}/tags', [TagController::class, 'setTattooTags']);
    Route::post('/tattoos/{tattooId}/tags/add', [TagController::class, 'addTattooTag']);

    // Tattoo management (authenticated)
    Route::delete('/tattoos/{id}', [\App\Http\Controllers\TattooController::class, 'destroy']);

    // Appointment routes
    Route::prefix('appointments')->group(function () {
        Route::post('/inbox', [AppointmentController::class, 'inbox']);
        Route::post('/history', [AppointmentController::class, 'history']);
        Route::post('/invite', [AppointmentController::class, 'invite']);
        Route::post('/event', [AppointmentController::class, 'createEvent']);
        Route::post('/{id}/respond', [AppointmentController::class, 'respondToRequest']);
        Route::put('/{id}', [AppointmentController::class, 'update']);
        Route::delete('/{id}', [AppointmentController::class, 'delete']);
    });

    // Message routes
    Route::prefix('messages')->group(function () {
        Route::get('/unread-count', [MessageController::class, 'getUnreadCount']);
        Route::get('/inbox', [MessageController::class, 'getInboxThreads']);
        Route::get('/appointment/{appointmentId}', [MessageController::class, 'getMessages']);
        Route::post('/send', [MessageController::class, 'sendMessage']);
        Route::put('/{messageId}/read', [MessageController::class, 'markAsRead']);
    });

    // Calendar integration routes
    Route::prefix('calendar')->group(function () {
        Route::get('/auth-url', [CalendarOAuthController::class, 'getAuthUrl']);
        Route::get('/status', [CalendarOAuthController::class, 'status']);
        Route::get('/events', [CalendarOAuthController::class, 'getEvents']);
        Route::post('/disconnect', [CalendarOAuthController::class, 'disconnect']);
        Route::post('/toggle-sync', [CalendarOAuthController::class, 'toggleSync']);
        Route::post('/sync', [CalendarOAuthController::class, 'triggerSync']);
    });

    // Bulk upload routes
    Route::prefix('bulk-uploads')->group(function () {
        Route::get('/', [\App\Http\Controllers\BulkUploadController::class, 'index']);
        Route::post('/', [\App\Http\Controllers\BulkUploadController::class, 'store']);
        Route::get('/{id}', [\App\Http\Controllers\BulkUploadController::class, 'show']);
        Route::delete('/{id}', [\App\Http\Controllers\BulkUploadController::class, 'destroy']);
        Route::get('/{id}/items', [\App\Http\Controllers\BulkUploadController::class, 'items']);
        Route::put('/{id}/items/{itemId}', [\App\Http\Controllers\BulkUploadController::class, 'updateItem']);
        Route::put('/{id}/items', [\App\Http\Controllers\BulkUploadController::class, 'batchUpdateItems']);
        Route::post('/{id}/process-batch', [\App\Http\Controllers\BulkUploadController::class, 'processBatch']);
        Route::post('/{id}/process-range', [\App\Http\Controllers\BulkUploadController::class, 'processRange']);
        Route::post('/{id}/publish', [\App\Http\Controllers\BulkUploadController::class, 'publish']);
        Route::get('/{id}/publish-status', [\App\Http\Controllers\BulkUploadController::class, 'publishStatus']);
    });

    // Tattoo lead routes (for users looking for tattoos)
    Route::prefix('leads')->group(function () {
        Route::get('/status', [TattooLeadController::class, 'status']);
        Route::get('/for-artists', [TattooLeadController::class, 'forArtists']);
        Route::post('/', [TattooLeadController::class, 'store']);
        Route::put('/', [TattooLeadController::class, 'update']);
        Route::post('/toggle', [TattooLeadController::class, 'toggle']);
    });
});

// Calendar OAuth callback (no auth required - user comes from Google)
Route::get('/calendar/callback', [CalendarOAuthController::class, 'handleCallback']);

// Webhook endpoints (no auth - verified by channel ID/signature)
Route::post('/webhooks/google-calendar', [CalendarWebhookController::class, 'handleGoogleWebhook']);

// Admin routes (requires authentication + admin privilege)
Route::middleware(['auth:sanctum', 'admin'])->prefix('admin')->group(function () {
    // Users
    Route::get('users', [UserController::class, 'adminIndex']);
    Route::post('users', [UserController::class, 'adminStore']);
    Route::get('users/{id}', [UserController::class, 'adminShow']);
    Route::put('users/{id}', [UserController::class, 'adminUpdate']);
    Route::delete('users/{id}', [UserController::class, 'adminDestroy']);

    // Studios
    Route::get('studios', [StudioController::class, 'adminIndex']);
    Route::post('studios', [StudioController::class, 'adminStore']);
    Route::get('studios/{id}', [StudioController::class, 'adminShow']);
    Route::put('studios/{id}', [StudioController::class, 'adminUpdate']);
    Route::delete('studios/{id}', [StudioController::class, 'adminDestroy']);

    // Tags
    Route::get('tags', [TagController::class, 'adminIndex']);
    Route::post('tags', [TagController::class, 'adminStore']);
    Route::get('tags/{id}', [TagController::class, 'adminShow']);
    Route::put('tags/{id}', [TagController::class, 'adminUpdate']);
    Route::delete('tags/{id}', [TagController::class, 'adminDestroy']);
    Route::post('tags/{id}/approve', [TagController::class, 'approve']);
    Route::post('tags/{id}/reject', [TagController::class, 'reject']);

    // Placements
    Route::get('placements', [PlacementController::class, 'adminIndex']);
    Route::post('placements', [PlacementController::class, 'adminStore']);
    Route::get('placements/{id}', [PlacementController::class, 'adminShow']);
    Route::put('placements/{id}', [PlacementController::class, 'adminUpdate']);
    Route::delete('placements/{id}', [PlacementController::class, 'adminDestroy']);

    // Blocked Terms
    Route::get('blocked-terms', [BlockedTermController::class, 'adminIndex']);
    Route::post('blocked-terms', [BlockedTermController::class, 'adminStore']);
    Route::get('blocked-terms/{id}', [BlockedTermController::class, 'adminShow']);
    Route::put('blocked-terms/{id}', [BlockedTermController::class, 'adminUpdate']);
    Route::delete('blocked-terms/{id}', [BlockedTermController::class, 'adminDestroy']);

    // Elastic operations
    Route::post('elastic/rebuild', [\App\Http\Controllers\ElasticController::class, 'rebuild']);
    Route::post('elastic/rebuild-bypass', [\App\Http\Controllers\ElasticController::class, 'rebuildBypass']);
    Route::post('elastic/rebuild-by-elastic', [\App\Http\Controllers\ElasticController::class, 'rebuildByElasticQuery']);
    Route::post('elastic/migrate', [\App\Http\Controllers\ElasticController::class, 'migrateAlias']);

    // Email testing
    Route::get('email-test/types', [EmailTestController::class, 'getTypes']);
    Route::post('email-test/send', [EmailTestController::class, 'send']);

    // Tattoos
    Route::get('tattoos', [\App\Http\Controllers\TattooController::class, 'adminIndex']);
    Route::get('tattoos/{id}', [\App\Http\Controllers\TattooController::class, 'adminShow']);
    Route::put('tattoos/{id}', [\App\Http\Controllers\TattooController::class, 'adminUpdate']);
});
