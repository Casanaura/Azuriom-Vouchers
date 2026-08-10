<?php

namespace Azuriom\Plugin\Vouchers\Requests;

use Azuriom\Http\Requests\Traits\ConvertCheckbox;
use Azuriom\Plugin\Vouchers\Models\Reward;
use Azuriom\Plugin\Vouchers\Models\Voucher;
use Azuriom\Plugin\Vouchers\Services\ShopPackageCatalog;
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
            'name' => $this->scalarInput('name'),
            'code' => $this->scalarInput('code'),
            'revision' => $this->scalarInput('revision'),
            'max_redemptions' => $this->optionalScalarInput('max_redemptions'),
            'max_redemptions_per_user' => $this->optionalScalarInput('max_redemptions_per_user'),
            'starts_at' => $this->optionalScalarInput('starts_at'),
            'expires_at' => $this->optionalScalarInput('expires_at'),
        ]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array|string>
     */
    public function rules(): array
    {
        $rewardTypes = [Reward::TYPE_MONEY];

        if ($this->shopPackages()->isAvailable()) {
            $rewardTypes[] = Reward::TYPE_SHOP_PACKAGE;
        }

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
            'rewards.*' => ['required', 'array'],
            'rewards.*.type' => ['required', Rule::in($rewardTypes)],
            'rewards.*.amount' => [
                'nullable',
                'required_if:rewards.*.type,'.Reward::TYPE_MONEY,
                'numeric', 'decimal:0,2', 'gt:0', 'max:999999999',
            ],
            'rewards.*.package_id' => [
                'nullable',
                'required_if:rewards.*.type,'.Reward::TYPE_SHOP_PACKAGE,
                'integer', 'min:1', 'max:4294967295',
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

            $shopRewards = collect($this->input('rewards', []))
                ->filter(fn (mixed $reward) => is_array($reward)
                    && ($reward['type'] ?? null) === Reward::TYPE_SHOP_PACKAGE);

            if ($shopRewards->isEmpty() || ! $this->shopPackages()->isAvailable()) {
                return;
            }

            $eligibleIds = $this->shopPackages()->eligibleIds($shopRewards->pluck('package_id'));

            foreach ($shopRewards as $index => $reward) {
                $packageId = filter_var($reward['package_id'] ?? null, FILTER_VALIDATE_INT);

                if ($packageId !== false && ! $eligibleIds->contains((int) $packageId)) {
                    $validator->errors()->add(
                        'rewards.'.$index.'.package_id',
                        trans('vouchers::admin.validation.package_unavailable'),
                    );
                }
            }
        });
    }

    private function shopPackages(): ShopPackageCatalog
    {
        return app(ShopPackageCatalog::class);
    }

    /**
     * Keep invalid submitted shapes scalar so validation and rendering remain safe.
     */
    private function scalarInput(string $key): mixed
    {
        $value = $this->input($key);

        return is_scalar($value) || $value === null ? $value : '__invalid__';
    }

    /**
     * Normalize empty optional values without accepting arrays as empty fields.
     */
    private function optionalScalarInput(string $key): mixed
    {
        $value = $this->scalarInput($key);

        return is_string($value) && trim($value) === '' ? null : $value;
    }
}
