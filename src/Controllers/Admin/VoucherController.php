<?php

namespace Azuriom\Plugin\Vouchers\Controllers\Admin;

use Azuriom\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;

class VoucherController extends Controller
{
    /**
     * Display the voucher administration landing page.
     */
    public function index(): View
    {
        return view('vouchers::admin.index');
    }
}
