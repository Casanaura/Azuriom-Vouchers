@include('admin.elements.date-picker', ['wrap' => true])

@csrf

@php($rewards = old('rewards', $formRewards))

<div class="row gx-3">
    <div class="mb-3 col-md-6">
        <label class="form-label" for="nameInput">{{ trans('vouchers::admin.fields.name') }}</label>
        <input type="text" class="form-control @error('name') is-invalid @enderror" id="nameInput" name="name" value="{{ old('name', $voucher->name) }}" maxlength="100" required>
        @error('name')
            <span class="invalid-feedback" role="alert"><strong>{{ $message }}</strong></span>
        @enderror
    </div>

    <div class="mb-3 col-md-6">
        <label class="form-label" for="codeInput">{{ trans('vouchers::admin.fields.code') }}</label>
        <div class="input-group @error('code') has-validation @enderror">
            <input type="text" class="form-control font-monospace @error('code') is-invalid @enderror" id="codeInput" name="code" value="{{ old('code', $voucher->code) }}" maxlength="80" autocomplete="off" required>
            <button type="button" class="btn btn-outline-secondary" id="generateCodeButton">
                <i class="bi bi-shuffle"></i> {{ trans('vouchers::admin.actions.generate') }}
            </button>
            @error('code')
                <span class="invalid-feedback" role="alert"><strong>{{ $message }}</strong></span>
            @enderror
        </div>
        <div class="form-text">{{ trans('vouchers::admin.help.code') }}</div>
    </div>
</div>

<div class="row gx-3">
    <div class="mb-3 col-md-6">
        <label class="form-label" for="globalLimitInput">{{ trans('vouchers::admin.fields.max_redemptions') }}</label>
        <input type="number" min="1" max="4294967295" class="form-control @error('max_redemptions') is-invalid @enderror" id="globalLimitInput" name="max_redemptions" value="{{ old('max_redemptions', $voucher->max_redemptions) }}">
        @error('max_redemptions')
            <span class="invalid-feedback" role="alert"><strong>{{ $message }}</strong></span>
        @enderror
        <div class="form-text">{{ trans('vouchers::admin.help.max_redemptions') }}</div>
    </div>

    <div class="mb-3 col-md-6">
        <label class="form-label" for="userLimitInput">{{ trans('vouchers::admin.fields.max_redemptions_per_user') }}</label>
        <input type="number" min="1" max="4294967295" class="form-control @error('max_redemptions_per_user') is-invalid @enderror" id="userLimitInput" name="max_redemptions_per_user" value="{{ old('max_redemptions_per_user', $voucher->max_redemptions_per_user) }}">
        @error('max_redemptions_per_user')
            <span class="invalid-feedback" role="alert"><strong>{{ $message }}</strong></span>
        @enderror
        <div class="form-text">{{ trans('vouchers::admin.help.max_redemptions_per_user') }}</div>
    </div>
</div>

<div class="row gx-3">
    <div class="mb-3 col-md-6">
        <label class="form-label" for="startInput">{{ trans('vouchers::admin.fields.starts_at') }}</label>
        <div class="input-group date-picker @error('starts_at') has-validation @enderror">
            <input type="text" class="form-control @error('starts_at') is-invalid @enderror" id="startInput" name="starts_at" value="{{ old('starts_at', $voucher->starts_at) }}" data-input>
            <button type="button" class="btn btn-outline-danger" title="{{ trans('messages.actions.delete') }}" aria-label="{{ trans('messages.actions.delete') }}" data-clear><i class="bi bi-x-lg"></i></button>
            @error('starts_at')
                <span class="invalid-feedback" role="alert"><strong>{{ $message }}</strong></span>
            @enderror
        </div>
    </div>

    <div class="mb-3 col-md-6">
        <label class="form-label" for="expiresInput">{{ trans('vouchers::admin.fields.expires_at') }}</label>
        <div class="input-group date-picker @error('expires_at') has-validation @enderror">
            <input type="text" class="form-control @error('expires_at') is-invalid @enderror" id="expiresInput" name="expires_at" value="{{ old('expires_at', $voucher->expires_at) }}" data-input>
            <button type="button" class="btn btn-outline-danger" title="{{ trans('messages.actions.delete') }}" aria-label="{{ trans('messages.actions.delete') }}" data-clear><i class="bi bi-x-lg"></i></button>
            @error('expires_at')
                <span class="invalid-feedback" role="alert"><strong>{{ $message }}</strong></span>
            @enderror
        </div>
    </div>
</div>

<div class="mb-3 form-check form-switch">
    <input type="checkbox" class="form-check-input" id="authenticationSwitch" name="requires_authentication" @checked(old('requires_authentication', $voucher->requires_authentication))>
    <label class="form-check-label" for="authenticationSwitch">{{ trans('vouchers::admin.fields.requires_authentication') }}</label>
    <div class="form-text">{{ trans('vouchers::admin.help.requires_authentication') }}</div>
</div>

<div class="mb-4 form-check form-switch">
    <input type="checkbox" class="form-check-input" id="enabledSwitch" name="is_enabled" @checked(old('is_enabled', $voucher->is_enabled))>
    <label class="form-check-label" for="enabledSwitch">{{ trans('vouchers::admin.fields.is_enabled') }}</label>
</div>

<div class="d-flex justify-content-between align-items-center mb-2">
    <div>
        <h2 class="h5 mb-1">{{ trans('vouchers::admin.rewards.title') }}</h2>
        <p class="text-muted mb-0">{{ trans('vouchers::admin.rewards.description') }}</p>
    </div>
    <button type="button" class="btn btn-outline-primary" id="addRewardButton">
        <i class="bi bi-plus-lg"></i> {{ trans('vouchers::admin.rewards.add') }}
    </button>
</div>

@error('rewards')
    <div class="alert alert-danger" role="alert">{{ $message }}</div>
@enderror

<div id="rewardsContainer">
    @foreach($rewards as $index => $reward)
        @include('vouchers::admin.codes._reward', ['index' => $index, 'reward' => $reward])
    @endforeach
</div>

<template id="rewardTemplate">
    @include('vouchers::admin.codes._reward', [
        'index' => '__INDEX__',
        'reward' => ['type' => \Azuriom\Plugin\Vouchers\Models\Reward::TYPE_MONEY, 'amount' => ''],
    ])
</template>

@push('footer-scripts')
    <script>
        document.getElementById('generateCodeButton').addEventListener('click', function () {
            const button = this;
            button.disabled = true;

            axios.post('{{ route('vouchers.admin.codes.generate') }}')
                .then(response => document.getElementById('codeInput').value = response.data.code)
                .catch(() => createAlert('danger', @json(trans('vouchers::admin.errors.generation_failed')), true))
                .finally(() => button.disabled = false);
        });

        const rewardsContainer = document.getElementById('rewardsContainer');
        const rewardTemplate = document.getElementById('rewardTemplate');
        let rewardIndex = 0;

        function updateRewardButtons() {
            const rows = rewardsContainer.querySelectorAll('[data-reward]');
            const removeDisabled = rows.length <= 1;

            rows.forEach(row => row.querySelector('[data-remove-reward]').disabled = removeDisabled);
            document.getElementById('addRewardButton').disabled = rows.length >= 50;
        }

        document.getElementById('addRewardButton').addEventListener('click', function () {
            while (document.getElementById(`rewardType${rewardIndex}`)) {
                rewardIndex++;
            }

            rewardsContainer.insertAdjacentHTML('beforeend', rewardTemplate.innerHTML.replaceAll('__INDEX__', rewardIndex++));
            updateRewardButtons();
        });

        rewardsContainer.addEventListener('click', function (event) {
            const button = event.target.closest('[data-remove-reward]');

            if (!button || rewardsContainer.querySelectorAll('[data-reward]').length <= 1) {
                return;
            }

            button.closest('[data-reward]').remove();
            updateRewardButtons();
        });

        updateRewardButtons();
    </script>
@endpush
