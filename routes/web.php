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
    Route::get('/{id}', 'UserController@getById');
});

Route::group(['prefix' => 'artists'], function () {
    Route::get('/{user_id?}', 'ArtistController@get');
    Route::post('/create', 'ArtistController@create');
    Route::put('/artist/{id}', 'ArtistController@update');
    Route::get('/{id}/{user_id?}', 'ArtistController@getById');
});

Route::group(['prefix' => 'studios'], function () {
    Route::get('/{user_id?}', 'StudioController@get');
    Route::post('/create', 'StudioController@create');
    Route::put('/studio/{id}', 'StudioController@update');
    Route::get('/{id}/{user_id?}', 'StudioController@getById');
});

Route::group(['prefix' => 'styles'], function () {
    Route::get('/', 'StyleController@get');
    Route::post('/create', 'StyleController@create');
    Route::put('/styles/{id}', 'StyleController@update');
    Route::get('/{id}', 'StyleController@getById');
});

Route::group(['prefix' => 'images'], function () {
    Route::post('/uploadPhoto', 'ImageController@upload');
});
