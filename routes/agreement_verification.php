<?php

use App\Http\Controllers\AgreementVerificationController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Agreement Verification Routes
|--------------------------------------------------------------------------
|
| These routes handle QR code verification for agreements.
| Add these to your main routes/web.php file.
|
*/

// Verify by token (from QR code)
Route::get('/verify/{token}', [AgreementVerificationController::class, 'verify'])
    ->name('verify.token')
    ->where('token', '[a-zA-Z0-9]+');

// Search by agreement number
Route::get('/verify', [AgreementVerificationController::class, 'verifyByNumber'])
    ->name('verify.search');