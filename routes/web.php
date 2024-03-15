<?php

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

Route::get('/', function () {
    return view('welcome');
});

Route::group(['prefix' => 'users'], function () {
    Route::post('/create', 'UserController@create');
    Route::put('/user/{id}', 'UserController@update');
    Route::post('/user/{id}/favorites', 'UserController@updateFavorite');
    Route::post('/uploadPhoto', 'UserController@upload');
    Route::get('/{id}', 'UserController@get');
});


Route::group(['prefix' => 'artists'], function () {
    Route::get('/{user_id?}', 'ArtistController@get');
    Route::post('/create', 'ArtistController@create');
    Route::put('/artist/{id}', 'ArtistController@update');
    Route::post('/uploadPhoto', 'ArtistController@upload');
    Route::get('/{id}/{user_id?}', 'ArtistController@getById');
});
