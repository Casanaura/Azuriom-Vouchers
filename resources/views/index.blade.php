@extends('layouts.app')

@section('title', trans('vouchers::messages.title'))

@section('content')
    <div class="row justify-content-center">
        <div class="col-lg-7 col-xl-6">
            <div class="card shadow-sm">
                <div class="card-body p-4">
                    <h1 class="h3">{{ trans('vouchers::messages.title') }}</h1>
                    <p class="text-muted">{{ trans('vouchers::messages.description') }}</p>

                    <form action="{{ route('vouchers.redeem') }}" method="POST">
                        @csrf
                        <input type="hidden" name="request_token" value="{{ old('request_token', $requestToken) }}">

                        <div class="mb-3">
                            <label class="form-label" for="codeInput">{{ trans('vouchers::messages.fields.code') }}</label>
                            <input type="text" class="form-control form-control-lg font-monospace text-uppercase @error('code') is-invalid @enderror" id="codeInput" name="code" maxlength="80" autocomplete="off" required autofocus>
                            @error('code')
                                <span class="invalid-feedback" role="alert"><strong>{{ $message }}</strong></span>
                            @enderror
                        </div>

                        @guest
                            <div class="mb-3">
                                <label class="form-label" for="usernameInput">{{ $userAttributeName }}</label>
                                <input type="text" class="form-control @error('username') is-invalid @enderror" id="usernameInput" name="username" value="{{ old('username') }}" maxlength="100" autocomplete="username">
                                @error('username')
                                    <span class="invalid-feedback" role="alert"><strong>{{ $message }}</strong></span>
                                @enderror
                                <div class="form-text">{{ trans('vouchers::messages.help.guest') }}</div>
                            </div>
                        @else
                            <div class="alert alert-info" role="status">
                                {{ trans('vouchers::messages.logged_as', ['user' => auth()->user()->name]) }}
                            </div>
                        @endguest

                        <button type="submit" class="btn btn-primary w-100">
                            <i class="bi bi-ticket-perforated"></i> {{ trans('vouchers::messages.actions.redeem') }}
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
