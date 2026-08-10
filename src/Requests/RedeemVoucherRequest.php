<?php

namespace Azuriom\Plugin\Vouchers\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Str;

class RedeemVoucherRequest extends FormRequest
{
    /**
     * Normalize public redemption input.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'code' => is_string($this->input('code')) ? trim($this->input('code')) : $this->input('code'),
            'username' => is_string($this->input('username')) ? trim($this->input('username')) : $this->input('username'),
        ]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array|string>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:80'],
            'username' => ['nullable', 'string', 'max:100'],
            'request_token' => ['required', 'uuid'],
        ];
    }

    /**
     * Keep bearer voucher codes out of the flashed session input.
     */
    protected function failedValidation(Validator $validator): void
    {
        $input = [];
        $username = $this->input('username');
        $requestToken = $this->input('request_token');

        if (is_string($username)) {
            $input['username'] = Str::limit($username, 100, '');
        }

        if (is_string($requestToken) && Str::isUuid($requestToken)) {
            $input['request_token'] = $requestToken;
        }

        throw new HttpResponseException(
            to_route('vouchers.index')
                ->withErrors($validator)
                ->withInput($input)
        );
    }
}
