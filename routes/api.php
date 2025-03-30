<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\StyleController;
use App\Http\Controllers\CountryController;

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

// Auth routes
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

// Protected routes
Route::middleware('auth:sanctum')->group(function () {
    // Auth
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::prefix('users')->group(function () {
        Route::get('/me', 'UserController@me');
    });


//    // User routes
//    Route::get('/users/{id}', [UserController::class, 'getById']);
//    Route::put('/users/{id}', [UserController::class, 'update']);
//    Route::put('/users/{id}/favorites', [UserController::class, 'updateFavorite']);
//    Route::post('/users/profile-photo', [UserController::class, 'upload']);
});
