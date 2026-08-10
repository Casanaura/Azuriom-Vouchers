<?php

namespace Azuriom\Plugin\Vouchers\Requests;

use Azuriom\Http\Requests\Traits\ConvertCheckbox;
use Azuriom\Plugin\Vouchers\Models\Reward;
use Azuriom\Plugin\Vouchers\Models\Voucher;
use Carbon\Carbon;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class VoucherRequest extends FormRequest
{
    use ConvertCheckbox {
        prepareForValidation as prepareCheckboxesForValidation;
    }

    /**
     * The attributes represented by checkboxes.
     *
     * @var array<int, string>
     */
    protected array $checkboxes = [
        'is_enabled', 'requires_authentication',
    ];

    /**
     * Normalize optional fields before validation.
     */
    protected function prepareForValidation(): void
    {
        $this->prepareCheckboxesForValidation();

        $this->merge([
            'max_redemptions' => $this->filled('max_redemptions')
                ? $this->input('max_redemptions')
                : null,
            'max_redemptions_per_user' => $this->filled('max_redemptions_per_user')
                ? $this->input('max_redemptions_per_user')
                : null,
            'starts_at' => $this->filled('starts_at') ? $this->input('starts_at') : null,
            'expires_at' => $this->filled('expires_at') ? $this->input('expires_at') : null,
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
            'name' => ['required', 'string', 'max:100'],
            'code' => ['required', 'string', 'max:80'],
            'is_enabled' => ['required', 'boolean'],
            'requires_authentication' => ['required', 'boolean'],
            'max_redemptions' => ['nullable', 'integer', 'min:1', 'max:4294967295'],
            'max_redemptions_per_user' => ['nullable', 'integer', 'min:1', 'max:4294967295'],
            'revision' => [$this->route('voucher') instanceof Voucher ? 'required' : 'nullable', 'integer', 'min:1'],
            'starts_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date'],
            'rewards' => ['required', 'array', 'min:1', 'max:50'],
            'rewards.*.type' => ['required', Rule::in([Reward::TYPE_MONEY])],
            'rewards.*.amount' => [
                'required_if:rewards.*.type,'.Reward::TYPE_MONEY,
                'numeric', 'decimal:0,2', 'gt:0', 'max:999999999',
            ],
        ];
    }

    /**
     * Add normalized code uniqueness and date range validation.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if (! $validator->errors()->has('code')) {
                $normalizedCode = Voucher::normalizeCode((string) $this->input('code'));

                if (! preg_match('/^[A-Z0-9]{8,64}$/', $normalizedCode)) {
                    $validator->errors()->add('code', trans('vouchers::admin.validation.code_format'));
                } else {
                    $query = Voucher::query()->whereCode((string) $this->input('code'));
                    $voucher = $this->route('voucher');

                    if ($voucher instanceof Voucher) {
                        $query->whereKeyNot($voucher->getKey());
                    }

                    if ($query->exists()) {
                        $validator->errors()->add('code', trans('vouchers::admin.validation.code_unique'));
                    }
                }
            }

            if (! $validator->errors()->hasAny(['starts_at', 'expires_at'])
                && $this->filled('starts_at')
                && $this->filled('expires_at')
                && Carbon::parse($this->input('expires_at'))->lte(Carbon::parse($this->input('starts_at')))) {
                $validator->errors()->add('expires_at', trans('vouchers::admin.validation.expires_after_start'));
            }
        });
    }
}
