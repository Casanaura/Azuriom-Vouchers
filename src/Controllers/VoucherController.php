<?php

namespace Azuriom\Plugin\Vouchers\Controllers;

use Azuriom\Http\Controllers\Controller;
use Azuriom\Plugin\Vouchers\Exceptions\VoucherRedemptionException;
use Azuriom\Plugin\Vouchers\Models\Redemption;
use Azuriom\Plugin\Vouchers\Requests\RedeemVoucherRequest;
use Azuriom\Plugin\Vouchers\Services\RedeemVoucher;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;

class VoucherController extends Controller
{
    /**
     * Show the public voucher redemption page.
     */
    public function index(): View
    {
        return view('vouchers::index', [
            'requestToken' => (string) Str::uuid(),
            'userAttributeName' => game()->userPrimaryAttributeName(),
        ]);
    }

    /**
     * Reserve and deliver a voucher to its recipient.
     *
     */
    public function redeem(RedeemVoucherRequest $request, RedeemVoucher $redeemVoucher): RedirectResponse
    {
        try {
            $redemption = $redeemVoucher->redeem(
                $request->string('code')->toString(),
                $request->user(),
                $request->input('username'),
                $request->string('request_token')->toString(),
                $request->ip(),
            );
        } catch (VoucherRedemptionException $exception) {
            $reason = match (true) {
                $exception->reason === VoucherRedemptionException::AUTHENTICATION_REQUIRED,
                $exception->reason === VoucherRedemptionException::RECIPIENT_REQUIRED => $exception->reason,
                $exception->reason === VoucherRedemptionException::USER_LIMIT_REACHED
                    && $request->user() !== null => $exception->reason,
                default => VoucherRedemptionException::UNAVAILABLE,
            };
            $field = $reason === VoucherRedemptionException::RECIPIENT_REQUIRED ? 'username' : 'code';

            return to_route('vouchers.index')
                ->withErrors([$field => trans('vouchers::messages.errors.'.$reason)])
                ->withInput($request->only('username'));
        }

        if ($redemption->status !== Redemption::STATUS_COMPLETED) {
            return to_route('vouchers.index')->with('error', trans('vouchers::messages.delivery_issue', [
                'reference' => Str::upper(Str::substr($redemption->uuid, 0, 8)),
            ]));
        }

        $message = $request->user() === null
            ? trans('vouchers::messages.redeemed_guest')
            : trans('vouchers::messages.redeemed', ['user' => $request->user()->name]);

        return to_route('vouchers.index')->with('success', $message);
    }
}
