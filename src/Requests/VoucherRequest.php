<?php

namespace Azuriom\Plugin\Vouchers\Requests;

use Azuriom\Http\Requests\Traits\ConvertCheckbox;
use Azuriom\Plugin\Vouchers\Models\Reward;
use Azuriom\Plugin\Vouchers\Models\Voucher;
use Azuriom\Plugin\Vouchers\Services\ServerCommandCatalog;
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

        $rewards = $this->input('rewards');

        if (is_array($rewards)) {
            $rewards = collect($rewards)->map(function (mixed $reward) {
                if (! is_array($reward)) {
                    return $reward;
                }

                foreach (['type', 'amount', 'package_id', 'server_id', 'command', 'require_online'] as $key) {
                    if (array_key_exists($key, $reward)
                        && ! is_scalar($reward[$key])
                        && $reward[$key] !== null) {
                        $reward[$key] = null;
                    }
                }

                return $reward;
            })->all();
        }

        $this->merge([
            'name' => $this->scalarInput('name'),
            'code' => $this->scalarInput('code'),
            'revision' => $this->scalarInput('revision'),
            'max_redemptions' => $this->optionalScalarInput('max_redemptions'),
            'max_redemptions_per_user' => $this->optionalScalarInput('max_redemptions_per_user'),
            'starts_at' => $this->optionalScalarInput('starts_at'),
            'expires_at' => $this->optionalScalarInput('expires_at'),
            'rewards' => $rewards,
        ]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array|string>
     */
    public function rules(): array
    {
        $rewardTypes = [Reward::TYPE_MONEY, Reward::TYPE_SERVER_COMMAND];

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
            'rewards.*.server_id' => [
                'nullable',
                'required_if:rewards.*.type,'.Reward::TYPE_SERVER_COMMAND,
                'integer', 'min:1', 'max:4294967295',
            ],
            'rewards.*.command' => [
                'nullable',
                'required_if:rewards.*.type,'.Reward::TYPE_SERVER_COMMAND,
                'string', 'max:4096',
                function (string $attribute, mixed $value, \Closure $fail) {
                    if (! is_string($value)) {
                        return;
                    }

                    $hasControls = preg_match('/[\x00-\x1F\x7F]/', $value) === 1;
                    $invalidShape = trim($value) === '' || str_starts_with(trim($value), '/');
                    $hasUnsupportedPlaceholder = preg_match_all(
                        '/\{([A-Za-z][A-Za-z0-9_]*)\}/',
                        $value,
                        $matches,
                    ) !== false && collect($matches[1] ?? [])->contains(
                        fn (string $placeholder) => ! in_array($placeholder, ['player', 'name'], true)
                    );

                    if ($hasControls || $invalidShape || $hasUnsupportedPlaceholder) {
                        $fail(trans('vouchers::admin.validation.command_format'));
                    }
                },
            ],
            'rewards.*.require_online' => [
                'nullable',
                'required_if:rewards.*.type,'.Reward::TYPE_SERVER_COMMAND,
                'boolean',
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

            $this->validateShopRewards($validator);
            $this->validateServerRewards($validator);
        });
    }

    /**
     * Confirm that every referenced Shop package still satisfies the integration contract.
     */
    private function validateShopRewards(Validator $validator): void
    {
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
    }

    /**
     * Confirm that every server can execute commands and supports the chosen condition.
     */
    private function validateServerRewards(Validator $validator): void
    {
        $serverRewards = collect($this->input('rewards', []))
            ->filter(fn (mixed $reward) => is_array($reward)
                && ($reward['type'] ?? null) === Reward::TYPE_SERVER_COMMAND);

        if ($serverRewards->isEmpty()) {
            return;
        }

        $servers = $this->serverCommands()->servers()->keyBy('id');

        foreach ($serverRewards as $index => $reward) {
            $serverId = filter_var($reward['server_id'] ?? null, FILTER_VALIDATE_INT);

            if ($serverId === false) {
                continue;
            }

            $server = $servers->get((int) $serverId);

            if ($server === null) {
                $validator->errors()->add(
                    'rewards.'.$index.'.server_id',
                    trans('vouchers::admin.validation.server_unavailable'),
                );

                continue;
            }

            if (filter_var($reward['require_online'] ?? false, FILTER_VALIDATE_BOOL)
                && ! $this->serverCommands()->supportsOnlineRequirement($server)) {
                $validator->errors()->add(
                    'rewards.'.$index.'.require_online',
                    trans('vouchers::admin.validation.online_requirement_unavailable'),
                );
            }
        }
    }

    private function shopPackages(): ShopPackageCatalog
    {
        return app(ShopPackageCatalog::class);
    }

    private function serverCommands(): ServerCommandCatalog
    {
        return app(ServerCommandCatalog::class);
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
