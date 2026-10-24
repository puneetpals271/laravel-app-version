<?php

use Illuminate\Support\Facades\Route;
use Puneetpal\AppVersion\Http\Controllers\VersionPolicyController;
use Puneetpal\AppVersion\Http\Middleware\EnforceAppVersion;

Route::prefix((string) config('app-version.route.prefix', 'app'))
    ->middleware((array) config('app-version.route.middleware', ['api']))
    ->group(function (): void {
        // A client that is already hard-blocked must still be able to read the
        // policy, so the enforcing middleware is excluded here even when the
        // application has appended it to the "api" group.
        Route::get('version-policy', VersionPolicyController::class)
            ->withoutMiddleware(EnforceAppVersion::class)
            ->name('app-version.policy');
    });
