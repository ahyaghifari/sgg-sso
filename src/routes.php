<?php

use Illuminate\Support\Facades\Route;
use Syifa\KeycloakSso\Http\Controllers\KeycloakSsoController;

Route::get('/redirect', [KeycloakSsoController::class, 'redirect'])->name('keycloak-sso.redirect');
Route::get('/callback', [KeycloakSsoController::class, 'callback'])->name('keycloak-sso.callback');
Route::post('/logout', [KeycloakSsoController::class, 'logout'])->name('keycloak-sso.logout');
