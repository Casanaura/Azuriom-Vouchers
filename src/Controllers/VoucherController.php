<?php

namespace Azuriom\Plugin\Vouchers\Controllers;

use Azuriom\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;

class VoucherController extends Controller
{
    /**
     * Display the public voucher redemption page.
     */
    public function index(): View
    {
        return view('vouchers::index');
    }
}
