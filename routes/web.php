<?php

use Illuminate\Support\Facades\Route;

// Übersicht aller Sendungen über das Download-Portal
Route::middleware(['web', 'auth'])
    ->prefix(config('nfshare.route_prefix', 'nfshare'))
    ->group(function () {
        Route::view('/', 'nfshare::pages.index')->name('nfshare.index');
    });
