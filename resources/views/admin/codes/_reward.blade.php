@php
    $rewardType = is_string($reward['type'] ?? null) ? $reward['type'] : null;
    $packageId = filter_var($reward['package_id'] ?? null, FILTER_VALIDATE_INT);
    $selectedPackageId = $packageId === false ? null : (int) $packageId;
    $selectedPackageAvailable = $selectedPackageId !== null && $shopPackages->contains('id', $selectedPackageId);
    $rewardAmount = is_scalar($reward['amount'] ?? null) ? (string) $reward['amount'] : '';
    $selectedPackageName = is_string($reward['package_name'] ?? null)
        ? $reward['package_name']
        : '#'.$selectedPackageId;
    $serverId = filter_var($reward['server_id'] ?? null, FILTER_VALIDATE_INT);
    $selectedServerId = $serverId === false ? null : (int) $serverId;
    $selectedServerAvailable = $selectedServerId !== null && $servers->contains('id', $selectedServerId);
    $selectedServerName = is_string($reward['server_name'] ?? null)
        ? $reward['server_name']
        : '#'.$selectedServerId;
    $rewardCommand = is_scalar($reward['command'] ?? null) ? (string) $reward['command'] : '';
    $requireOnline = in_array($reward['require_online'] ?? false, [true, 1, '1'], true);
    $roleId = filter_var($reward['role_id'] ?? null, FILTER_VALIDATE_INT);
    $selectedRoleId = $roleId === false || $roleId < 1 ? null : (int) $roleId;
    $selectedRoleAvailable = $selectedRoleId !== null && $internalRoles->contains('id', $selectedRoleId);
    $selectedRoleName = is_string($reward['role_name'] ?? null)
        ? $reward['role_name']
        : '#'.$selectedRoleId;
    $knownRewardType = is_string($rewardType) && in_array($rewardType, [
        \Azuriom\Plugin\Vouchers\Models\Reward::TYPE_MONEY,
        \Azuriom\Plugin\Vouchers\Models\Reward::TYPE_SHOP_PACKAGE,
        \Azuriom\Plugin\Vouchers\Models\Reward::TYPE_SERVER_COMMAND,
        \Azuriom\Plugin\Vouchers\Models\Reward::TYPE_INTERNAL_ROLE,
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
                    @if($servers->isNotEmpty() || $rewardType === 'server_command')
                        <option value="server_command" @selected($rewardType === 'server_command')>
                            {{ trans('vouchers::admin.rewards.types.server_command') }}
                            @if($servers->isEmpty())
                                — {{ trans('vouchers::admin.rewards.server_unavailable') }}
                            @endif
                        </option>
                    @endif
                    @if($internalRoles->isNotEmpty() || $rewardType === 'internal_role')
                        <option value="internal_role" @selected($rewardType === 'internal_role')>
                            {{ trans('vouchers::admin.rewards.types.internal_role') }}
                            @if($internalRoles->isEmpty())
                                — {{ trans('vouchers::admin.rewards.role_unavailable') }}
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
                        @php
                            $category = $categoryPackages->first()->category;
                        @endphp
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

            <div class="col-12" data-reward-fields="server_command" @if($rewardType !== 'server_command') hidden @endif>
                <div class="row gx-3">
                    <div class="col-lg-4 mb-3 mb-lg-0">
                        <label class="form-label" for="rewardServer{{ $index }}">{{ trans('vouchers::admin.rewards.server') }}</label>
                        <select class="form-select @error('rewards.'.$index.'.server_id') is-invalid @enderror" id="rewardServer{{ $index }}" name="rewards[{{ $index }}][server_id]" data-active-required @disabled($rewardType !== 'server_command') @required($rewardType === 'server_command')>
                            <option value="">{{ trans('vouchers::admin.rewards.select_server') }}</option>
                            @if($selectedServerId !== null && ! $selectedServerAvailable)
                                <option value="{{ $selectedServerId }}" selected>
                                    {{ $selectedServerName }} — {{ trans('vouchers::admin.rewards.server_unavailable') }}
                                </option>
                            @endif
                            @foreach($servers as $server)
                                <option value="{{ $server->id }}" @selected($selectedServerId === (int) $server->id)>
                                    {{ $server->name }} (#{{ $server->id }})
                                </option>
                            @endforeach
                        </select>
                        @error('rewards.'.$index.'.server_id')
                            <span class="invalid-feedback" role="alert"><strong>{{ $message }}</strong></span>
                        @enderror
                    </div>

                    <div class="col-lg-5 mb-3 mb-lg-0">
                        <label class="form-label" for="rewardCommand{{ $index }}">{{ trans('vouchers::admin.rewards.command') }}</label>
                        <input type="text" class="form-control font-monospace @error('rewards.'.$index.'.command') is-invalid @enderror" id="rewardCommand{{ $index }}" name="rewards[{{ $index }}][command]" value="{{ $rewardCommand }}" maxlength="4096" autocomplete="off" data-active-required @disabled($rewardType !== 'server_command') @required($rewardType === 'server_command')>
                        @error('rewards.'.$index.'.command')
                            <span class="invalid-feedback" role="alert"><strong>{{ $message }}</strong></span>
                        @enderror
                    </div>

                    <div class="col-lg-3">
                        <label class="form-label" for="rewardOnline{{ $index }}">{{ trans('vouchers::admin.rewards.execution_condition') }}</label>
                        <select class="form-select @error('rewards.'.$index.'.require_online') is-invalid @enderror" id="rewardOnline{{ $index }}" name="rewards[{{ $index }}][require_online]" data-active-required @disabled($rewardType !== 'server_command') @required($rewardType === 'server_command')>
                            <option value="0" @selected(! $requireOnline)>{{ trans('vouchers::admin.rewards.conditions.immediate') }}</option>
                            <option value="1" @selected($requireOnline)>{{ trans('vouchers::admin.rewards.conditions.online') }}</option>
                        </select>
                        @error('rewards.'.$index.'.require_online')
                            <span class="invalid-feedback" role="alert"><strong>{{ $message }}</strong></span>
                        @enderror
                    </div>
                </div>
                <div class="form-text">{{ trans('vouchers::admin.help.server_command') }}</div>
            </div>

            <div class="col-md-6" data-reward-fields="internal_role" @if($rewardType !== 'internal_role') hidden @endif>
                <label class="form-label" for="rewardRole{{ $index }}">{{ trans('vouchers::admin.rewards.role') }}</label>
                <select class="form-select @error('rewards.'.$index.'.role_id') is-invalid @enderror" id="rewardRole{{ $index }}" name="rewards[{{ $index }}][role_id]" data-active-required @disabled($rewardType !== 'internal_role') @required($rewardType === 'internal_role')>
                    <option value="">{{ trans('vouchers::admin.rewards.select_role') }}</option>
                    @if($selectedRoleId !== null && ! $selectedRoleAvailable)
                        <option value="{{ $selectedRoleId }}" selected>
                            {{ $selectedRoleName }} — {{ trans('vouchers::admin.rewards.role_unavailable') }}
                        </option>
                    @endif
                    @foreach($internalRoles as $role)
                        <option value="{{ $role->id }}" @selected($selectedRoleId === (int) $role->id)>
                            {{ $role->name }} (#{{ $role->id }})
                        </option>
                    @endforeach
                </select>
                @error('rewards.'.$index.'.role_id')
                    <span class="invalid-feedback" role="alert"><strong>{{ $message }}</strong></span>
                @enderror
                <div class="form-text">{{ trans('vouchers::admin.help.internal_role') }}</div>
            </div>
        </div>
    </div>
</div>
