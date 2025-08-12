<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\StyleController;
use App\Http\Controllers\CountryController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\AppointmentController;

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
Route::post('/username', [AuthController::class, 'checkUsername']);
Route::post('/login', [AuthController::class, 'login']);
Route::post('/logout', [AuthController::class, 'logout']);

// Protected routes
Route::middleware('auth:sanctum')->group(function () {
    Route::prefix('users')->group(function () {
        Route::get('/me', [UserController::class, 'me']);
    });

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
