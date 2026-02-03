<?php

use App\Http\Controllers\AppointmentController;
use App\Http\Controllers\ArtistController;
use App\Http\Controllers\ClientDashboardController;
use App\Http\Controllers\ConversationController;
use App\Http\Controllers\ElasticController;
use App\Http\Controllers\ImageController;
use App\Http\Controllers\TattooController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\StudioController;
use App\Http\Controllers\TagController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

Route::get('/test', function () {
   return "hello";
});

Route::prefix('api')->group(function () {
    // Public tattoo routes - optional auth to filter blocked artists
    Route::middleware('auth.optional')->group(function () {
        Route::group(['prefix' => 'tattoos'], function () {
            Route::post('/', [TattooController::class, 'search']);
            Route::get('/{id}', [TattooController::class, 'getById']);
        });
    });

    Route::middleware('auth:sanctum')->group(function () {
        Route::group(['prefix' => 'users'], function () {
            Route::post('profile-photo', [UserController::class, 'upload']);
            Route::put('/{id}', [UserController::class, 'update']);
            Route::post('/favorites/{type}', [UserController::class, 'toggleFavorite']);
            Route::delete('/favorites/{type}/{id}', [UserController::class, 'removeFavorite']);
            Route::get('/{id}', [UserController::class, 'getById']);
        });

        Route::group(['prefix' => 'tattoos'], function () {
            Route::post('/create', [TattooController::class, 'create']);
            Route::match(['put', 'post'], '/{id}', [TattooController::class, 'update']);
            Route::post('/{id}/generate-tags', [TattooController::class, 'generateTags']);
            Route::put('/{id}/featured', [TattooController::class, 'toggleFeatured']);
        });
    });

    Route::group(['prefix' => 'artists'], function () {
        // Public artist routes - optional auth to filter blocked users
        Route::middleware('auth.optional')->group(function () {
            Route::post('/', [ArtistController::class, 'search']);
            Route::get('/{id}', [ArtistController::class, 'getById']);
            Route::get('/{id}/working-hours', [ArtistController::class, 'getAvailability']);
        });
        Route::post('/{id}/view', [ArtistController::class, 'recordView']);

        // Protected artist routes - require authentication
        Route::middleware('auth:sanctum')->group(function () {
            Route::post('/lookup', [ArtistController::class, 'lookupByIdentifier']);
            Route::put('/{id}', [ArtistController::class, 'update']);
            Route::post('/{id}/working-hours', [ArtistController::class, 'setAvailability']);
            Route::get('/{id}/settings', [ArtistController::class, 'getSettings']);
            Route::put('/{id}/settings', [ArtistController::class, 'updateSettings']);
            Route::get('/{id}/dashboard-stats', [ArtistController::class, 'getDashboardStats']);
            Route::get('/{id}/upcoming-schedule', [ArtistController::class, 'getUpcomingSchedule']);
        });
    });

    Route::middleware('auth:sanctum')->group(function () {
        Route::group(['prefix' => 'appointments'], function () {
            Route::post('/create', [AppointmentController::class, 'store']);
            Route::post('/inbox', [AppointmentController::class, 'inbox']);
            Route::post('/history', [AppointmentController::class, 'history']);
            Route::put('/{id}', [AppointmentController::class, 'update']);
            Route::get('/{id}', [AppointmentController::class, 'getById']);
            Route::delete('/{id', [AppointmentController::class, 'delete']);
        });

        // Conversations / Messages
        Route::group(['prefix' => 'conversations'], function () {
            Route::get('/', [ConversationController::class, 'index']);
            Route::post('/', [ConversationController::class, 'store']);
            Route::get('/unread-count', [ConversationController::class, 'getUnreadCount']);
            Route::get('/{id}', [ConversationController::class, 'show']);
            Route::put('/{id}/read', [ConversationController::class, 'markAsRead']);
            Route::get('/{id}/messages', [ConversationController::class, 'getMessages']);
            Route::post('/{id}/messages', [ConversationController::class, 'sendMessage']);
            Route::post('/{id}/messages/booking-card', [ConversationController::class, 'sendBookingCard']);
            Route::post('/{id}/messages/deposit-request', [ConversationController::class, 'sendDepositRequest']);
            Route::post('/{id}/messages/design-share', [ConversationController::class, 'sendDesignShare']);
            Route::post('/{id}/messages/price-quote', [ConversationController::class, 'sendPriceQuote']);
        });

        // Client Dashboard
        Route::group(['prefix' => 'client'], function () {
            Route::get('/dashboard', [ClientDashboardController::class, 'index']);
            Route::get('/favorites', [ClientDashboardController::class, 'getFavorites']);
            Route::get('/wishlist', [ClientDashboardController::class, 'getWishlist']);
            Route::post('/wishlist', [ClientDashboardController::class, 'addToWishlist']);
            Route::put('/wishlist/{artistId}', [ClientDashboardController::class, 'updateWishlistItem']);
            Route::delete('/wishlist/{artistId}', [ClientDashboardController::class, 'removeFromWishlist']);
            Route::get('/suggested-artists', [ClientDashboardController::class, 'getSuggestedArtistsEndpoint']);
        });
    });

    Route::group(['prefix' => 'studios'], function () {
        // Public studio routes - for guests to view
        Route::get('/{id}', [StudioController::class, 'getById']);
        Route::get('/{id}/announcements', [StudioController::class, 'getAnnouncements']);
        Route::get('/{id}/spotlights', [StudioController::class, 'getSpotlights']);
        Route::get('/{id}/artists', [StudioController::class, 'getArtists']);
        Route::get('/{id}/working-hours', [StudioController::class, 'getAvailability']);

        // Protected studio routes - require authentication
        Route::middleware('auth:sanctum')->group(function () {
            Route::post('/', [StudioController::class, 'create']);
            Route::post('/{id}/claim', [StudioController::class, 'claim']);
            Route::post('/{id}/image', [StudioController::class, 'uploadImage']);
            Route::put('/studio/{id}', [StudioController::class, 'update']);
            Route::put('/studios/studio-hours/{id}', [StudioController::class, 'updateBusinessHours']);
            Route::post('/{id}/working-hours', [StudioController::class, 'setAvailability']);
            Route::get('/{id}/dashboard-stats', [StudioController::class, 'getDashboardStats']);
            Route::get('/{id}/dashboard', [StudioController::class, 'dashboard']);

            // Artist management
            Route::post('/{id}/artists', [StudioController::class, 'addArtist']);
            Route::delete('/{id}/artists/{userId}', [StudioController::class, 'removeArtist']);
            Route::post('/{id}/artists/{userId}/verify', [StudioController::class, 'verifyArtist']);
            Route::post('/{id}/artists/{userId}/unverify', [StudioController::class, 'unverifyArtist']);

            // Announcements
            Route::post('/{id}/announcements', [StudioController::class, 'createAnnouncement']);
            Route::put('/{id}/announcements/{announcementId}', [StudioController::class, 'updateAnnouncement']);
            Route::delete('/{id}/announcements/{announcementId}', [StudioController::class, 'deleteAnnouncement']);

            // Spotlights
            Route::post('/{id}/spotlights', [StudioController::class, 'addSpotlight']);
            Route::delete('/{id}/spotlights/{spotlightId}', [StudioController::class, 'removeSpotlight']);
        });
    });

    Route::group(['prefix' => 'styles'], function () {
        Route::get('/', 'StyleController@get');
        Route::post('/create', 'StyleController@create');
        Route::put('/style/{id}', 'StyleController@update');
        Route::get('/{id}', 'StyleController@getById');
    });

    // Tags routes
    Route::group(['prefix' => 'tags'], function () {
        // Public routes
        Route::get('/', [TagController::class, 'index']);
        Route::get('/search', [TagController::class, 'search']);
        Route::get('/featured', [TagController::class, 'featured']);
        Route::get('/{slug}', [TagController::class, 'show']);

        // Protected routes for managing tattoo tags
        Route::middleware('auth:sanctum')->group(function () {
            Route::get('/tattoo/{tattooId}', [TagController::class, 'getTattooTags']);
            Route::post('/tattoo/{tattooId}', [TagController::class, 'setTattooTags']);
            Route::post('/tattoo/{tattooId}/add', [TagController::class, 'addTattooTag']);
            Route::delete('/tattoo/{tattooId}/{tagId}', [TagController::class, 'removeTattooTag']);
            Route::post('/tattoo/{tattooId}/generate', [TagController::class, 'generateTattooTags']);
        });
    });

    Route::group(['prefix' => 'images'], function () {
        Route::post('/uploadPhoto', [ImageController::class, 'upload']);
    });

    Route::group(['prefix' => 'elastic'], function () {
        // Public search routes - for guests to search
        Route::post('/', 'SearchController@index');
        Route::get('/{id}', 'ElasticController@getById');
        Route::post('/initial-search', 'SearchController@getInitialSearch');

        // Protected elastic routes - require authentication (admin operations)
        Route::middleware('auth:sanctum')->group(function () {
            Route::post('/rebuild', [ElasticController::class, 'rebuild']);
            Route::post('/rebuild-by-elastic', [ElasticController::class, 'rebuildByElasticQuery']);
            Route::post('/rebuild-bypass', [ElasticController::class, 'rebuildBypass']);
            Route::post('/migrate', [ElasticController::class, 'migrateAlias']);
            Route::post('translate-query', [ElasticController::class, 'translateQuery']);
            Route::post('reindex', [ElasticController::class, 'reindex']);
        });
    });

    Route::group(['prefix' => 'countries'], function () {
        Route::get('/', 'CountryController@get');
    });
});
