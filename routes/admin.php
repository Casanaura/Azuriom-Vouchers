<?php

use Azuriom\Plugin\Vouchers\Controllers\Admin\VoucherController;
use Illuminate\Support\Facades\Route;

Route::middleware('can:vouchers.admin')->group(function () {
    Route::get('/', [VoucherController::class, 'index'])->name('index');
});
