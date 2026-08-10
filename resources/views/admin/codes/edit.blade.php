@extends('admin.layouts.admin')

@section('title')
    {{ trans('vouchers::admin.codes.edit', ['voucher' => $voucher->name]) }}
@endsection

@section('content')
    <form action="{{ route('vouchers.admin.codes.update', $voucher) }}" method="POST">
        @method('PUT')
        <input type="hidden" name="revision" value="{{ old('revision', $voucher->revision) }}">

        <div class="card shadow mb-4">
            <div class="card-body">
                @error('revision')
                    <div class="alert alert-warning" role="alert">{{ $message }}</div>
                @enderror

                @include('vouchers::admin.codes._form')

                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-save"></i> {{ trans('messages.actions.save') }}
                </button>
            </div>
        </div>
    </form>
@endsection
