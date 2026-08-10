<?php

use Azuriom\Plugin\Vouchers\Controllers\VoucherController;
use Illuminate\Support\Facades\Route;

Route::get('/', [VoucherController::class, 'index'])->name('index');
