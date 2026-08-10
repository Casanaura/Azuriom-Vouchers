<?php

use Azuriom\Plugin\Vouchers\Controllers\VoucherController;
use Illuminate\Support\Facades\Route;

Route::get('/', [VoucherController::class, 'index'])->name('index');
Route::post('/redeem', [VoucherController::class, 'redeem'])
    ->middleware('throttle:10,1,vouchers-redeem')
    ->name('redeem');
