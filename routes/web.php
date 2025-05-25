<?php

use App\Http\Controllers\AppointmentController;
use App\Http\Controllers\ArtistController;
use App\Http\Controllers\ImageController;
use App\Http\Controllers\TattooController;
use App\Http\Controllers\UserController;
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
    // Public tattoo routes - for guests to search and view
    Route::group(['prefix' => 'tattoos'], function () {
        Route::post('/', [TattooController::class, 'search']);
        Route::get('/{id}', [TattooController::class, 'getById']);
    });
    
    Route::middleware('auth:sanctum')->group(function () {
        Route::group(['prefix' => 'users'], function () {
            Route::post('profile-photo', [UserController::class, 'upload']);
            Route::put('/{id}', [UserController::class, 'update']);
            Route::post('/favorites/{type}', [UserController::class, 'updateFavorite']);
            Route::get('/{id}', [UserController::class, 'getById']);
        });

        Route::group(['prefix' => 'tattoos'], function () {
            Route::post('/create', [TattooController::class, 'create']);
            Route::put('/tattoos/{id}', [TattooController::class, 'update']);
        });
    });

    Route::group(['prefix' => 'artists'], function () {
        // Public artist routes - for guests to view and search
        Route::post('/', [ArtistController::class, 'search']);
        Route::get('/{id}', [ArtistController::class, 'getById']);
        Route::get('/{id}/working-hours', [ArtistController::class, 'getAvailability']);
        Route::get('/{id}/portfolio', [ArtistController::class, 'portfolio']);
        
        // Protected artist routes - require authentication
        Route::middleware('auth:sanctum')->group(function () {
            Route::put('/artist/{id}', [ArtistController::class, 'update']);
            Route::post('/{id}/working-hours', [ArtistController::class, 'setAvailability']);

            Route::group(['prefix' => 'appointments'], function () {
                //get available appointment times
                Route::post('/', [AppointmentController::class, 'index']);
                Route::post('/create', [AppointmentController::class, 'store']);
                Route::post('/inbox', [AppointmentController::class, 'inbox']);
                Route::post('/history', [AppointmentController::class, 'history']);
                Route::put('/{id}', [AppointmentController::class, 'update']);
                Route::get('/{id}', [AppointmentController::class, 'getById']);
                Route::delete('/{id', [AppointmentController::class, 'delete']);
            });
        });
    });

    Route::group(['prefix' => 'studios'], function () {
        // Public studio routes - for guests to view
        Route::get('/{user_id?}', 'StudioController@get');
        Route::get('/studio/{id}', 'StudioController@getById');
        Route::get('/{id}/{user_id?}', 'StudioController@getById');
        
        // Protected studio routes - require authentication
        Route::middleware('auth:sanctum')->group(function () {
            Route::post('/', 'StudioController@create');
            Route::put('/studio/{id}', 'StudioController@update');
            Route::put('/studios/studio-hours/{id}', 'StudioController@updateBusinessHours');
        });
    });

    Route::group(['prefix' => 'styles'], function () {
        Route::get('/', 'StyleController@get');
        Route::post('/create', 'StyleController@create');
        Route::put('/style/{id}', 'StyleController@update');
        Route::get('/{id}', 'StyleController@getById');
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
            Route::post('/rebuild', 'ElasticController@rebuild');
            Route::post('/rebuild-by-elastic', 'ElasticController@rebuildByElasticQuery');
            Route::post('/rebuild-bypass', 'ElasticController@rebuildBypass');
            Route::post('/migrate', 'ElasticController@migrateAlias');
            Route::post('translate-query', 'ElasticController@translateQuery');
        });
    });

    Route::group(['prefix' => 'countries'], function () {
        Route::get('/', 'CountryController@get');
    });
});
