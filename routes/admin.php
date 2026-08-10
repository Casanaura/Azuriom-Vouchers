<?php

use Azuriom\Plugin\Vouchers\Controllers\Admin\VoucherController;
use Illuminate\Support\Facades\Route;

Route::middleware('can:vouchers.admin')->group(function () {
    Route::get('/', [VoucherController::class, 'index'])->name('codes.index');
    Route::get('/index', [VoucherController::class, 'index'])->name('index');
    Route::get('/create', [VoucherController::class, 'create'])->name('codes.create');
    Route::post('/', [VoucherController::class, 'store'])->name('codes.store');
    Route::post('/generate', [VoucherController::class, 'generate'])->name('codes.generate');
    Route::get('/{voucher}/edit', [VoucherController::class, 'edit'])->name('codes.edit');
    Route::put('/{voucher}', [VoucherController::class, 'update'])->name('codes.update');
    Route::delete('/{voucher}', [VoucherController::class, 'destroy'])->name('codes.destroy');
});
