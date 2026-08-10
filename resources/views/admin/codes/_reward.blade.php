@php
    $rewardType = is_string($reward['type'] ?? null) ? $reward['type'] : null;
    $packageId = filter_var($reward['package_id'] ?? null, FILTER_VALIDATE_INT);
    $selectedPackageId = $packageId === false ? null : (int) $packageId;
    $selectedPackageAvailable = $selectedPackageId !== null && $shopPackages->contains('id', $selectedPackageId);
    $rewardAmount = is_scalar($reward['amount'] ?? null) ? (string) $reward['amount'] : '';
    $selectedPackageName = is_string($reward['package_name'] ?? null)
        ? $reward['package_name']
        : '#'.$selectedPackageId;
    $knownRewardType = is_string($rewardType) && in_array($rewardType, [
        \Azuriom\Plugin\Vouchers\Models\Reward::TYPE_MONEY,
        \Azuriom\Plugin\Vouchers\Models\Reward::TYPE_SHOP_PACKAGE,
    ], true);
@endphp

<div class="card mb-3" data-reward>
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <strong>{{ trans('vouchers::admin.rewards.reward') }}</strong>
            <button type="button" class="btn btn-sm btn-outline-danger" data-remove-reward title="{{ trans('messages.actions.delete') }}" aria-label="{{ trans('messages.actions.delete') }}">
                <i class="bi bi-trash"></i>
            </button>
        </div>

        <div class="row gx-3">
            <div class="col-md-6 mb-3 mb-md-0">
                <label class="form-label" for="rewardType{{ $index }}">{{ trans('vouchers::admin.rewards.type') }}</label>
                <select class="form-select @error('rewards.'.$index.'.type') is-invalid @enderror" id="rewardType{{ $index }}" name="rewards[{{ $index }}][type]" data-reward-type required>
                    @if(! $knownRewardType)
                        <option value="{{ $rewardType ?? '' }}" selected>
                            @if($rewardType === null)
                                {{ trans('vouchers::admin.rewards.unsupported_type_unknown') }}
                            @else
                                {{ trans('vouchers::admin.rewards.unsupported_type', ['type' => $rewardType]) }}
                            @endif
                        </option>
                    @endif
                    <option value="money" @selected($rewardType === 'money')>{{ trans('vouchers::admin.rewards.types.money') }}</option>
                    @if($shopPackages->isNotEmpty() || $rewardType === 'shop_package')
                        <option value="shop_package" @selected($rewardType === 'shop_package')>
                            {{ trans('vouchers::admin.rewards.types.shop_package') }}
                            @if(! $shopAvailable)
                                — {{ trans('vouchers::admin.rewards.shop_unavailable') }}
                            @elseif($shopPackages->isEmpty())
                                — {{ trans('vouchers::admin.rewards.package_unavailable') }}
                            @endif
                        </option>
                    @endif
                </select>
                @error('rewards.'.$index.'.type')
                    <span class="invalid-feedback" role="alert"><strong>{{ $message }}</strong></span>
                @enderror
            </div>

            <div class="col-md-6" data-reward-fields="money" @if($rewardType !== 'money') hidden @endif>
                <label class="form-label" for="rewardAmount{{ $index }}">{{ trans('vouchers::admin.rewards.amount') }}</label>
                <input type="number" min="0.01" max="999999999" step="0.01" class="form-control @error('rewards.'.$index.'.amount') is-invalid @enderror" id="rewardAmount{{ $index }}" name="rewards[{{ $index }}][amount]" value="{{ $rewardAmount }}" data-active-required @disabled($rewardType !== 'money') @required($rewardType === 'money')>
                @error('rewards.'.$index.'.amount')
                    <span class="invalid-feedback" role="alert"><strong>{{ $message }}</strong></span>
                @enderror
            </div>

            <div class="col-md-6" data-reward-fields="shop_package" @if($rewardType !== 'shop_package') hidden @endif>
                <label class="form-label" for="rewardPackage{{ $index }}">{{ trans('vouchers::admin.rewards.package') }}</label>
                <select class="form-select @error('rewards.'.$index.'.package_id') is-invalid @enderror" id="rewardPackage{{ $index }}" name="rewards[{{ $index }}][package_id]" data-active-required @disabled($rewardType !== 'shop_package') @required($rewardType === 'shop_package')>
                    <option value="">{{ trans('vouchers::admin.rewards.select_package') }}</option>
                    @if($selectedPackageId !== null && ! $selectedPackageAvailable)
                        <option value="{{ $selectedPackageId }}" selected>
                            {{ $selectedPackageName }} — {{ trans('vouchers::admin.rewards.package_unavailable') }}
                        </option>
                    @endif
                    @foreach($shopPackages->groupBy('category_id') as $categoryPackages)
                        @php($category = $categoryPackages->first()->category)
                        <optgroup label="{{ $category?->name ?? trans('messages.unknown') }}">
                            @foreach($categoryPackages as $package)
                                <option value="{{ $package->id }}" @selected($selectedPackageId === (int) $package->id)>
                                    {{ $package->name }} (#{{ $package->id }})
                                    @if(! $package->is_enabled || ! $package->category?->is_enabled)
                                        — {{ trans('vouchers::admin.rewards.package_disabled') }}
                                    @endif
                                    @if($package->billing_type === 'expiring')
                                        — {{ $package->billing_period }}
                                    @endif
                                </option>
                            @endforeach
                        </optgroup>
                    @endforeach
                </select>
                @error('rewards.'.$index.'.package_id')
                    <span class="invalid-feedback" role="alert"><strong>{{ $message }}</strong></span>
                @enderror
                <div class="form-text">{{ trans('vouchers::admin.help.shop_package') }}</div>
            </div>
        </div>
    </div>
</div>
