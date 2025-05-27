<?php

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Route;
use Paymenter\Extensions\Gateways\NOWPayments\NOWPayments;

Route::post('/extensions/gateways/nowpayments/webhook', [NOWPayments::class, 'webhook'])
    ->withoutMiddleware([VerifyCsrfToken::class])
    ->name('extensions.gateways.nowpayments.webhook');
