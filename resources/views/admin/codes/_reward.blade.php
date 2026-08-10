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
                <select class="form-select @error('rewards.'.$index.'.type') is-invalid @enderror" id="rewardType{{ $index }}" name="rewards[{{ $index }}][type]" required>
                    <option value="money" @selected(($reward['type'] ?? null) === 'money')>{{ trans('vouchers::admin.rewards.types.money') }}</option>
                </select>
                @error('rewards.'.$index.'.type')
                    <span class="invalid-feedback" role="alert"><strong>{{ $message }}</strong></span>
                @enderror
            </div>

            <div class="col-md-6">
                <label class="form-label" for="rewardAmount{{ $index }}">{{ trans('vouchers::admin.rewards.amount') }}</label>
                <input type="number" min="0.01" step="0.01" class="form-control @error('rewards.'.$index.'.amount') is-invalid @enderror" id="rewardAmount{{ $index }}" name="rewards[{{ $index }}][amount]" value="{{ $reward['amount'] ?? '' }}" required>
                @error('rewards.'.$index.'.amount')
                    <span class="invalid-feedback" role="alert"><strong>{{ $message }}</strong></span>
                @enderror
            </div>
        </div>
    </div>
</div>
