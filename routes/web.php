<?php

use Illuminate\Support\Facades\Route;

// Übersicht aller Sendungen über das Download-Portal
Route::middleware(['web', 'auth'])
    ->prefix(config('nfshare.route_prefix', 'nfshare'))
    ->group(function () {
        Route::view('/', 'nfshare::pages.index')->name('nfshare.index');

        // Abgeholte Uploads, die auf einer Disk liegen (ohne nfgarching/files)
        Route::get('eingang/{upload}', [\Nfgarching\Share\Http\UploadDownloadController::class, 'show'])
            ->whereNumber('upload')->name('nfshare.upload.download');
    });
