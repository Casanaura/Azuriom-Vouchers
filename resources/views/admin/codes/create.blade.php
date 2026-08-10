@extends('admin.layouts.admin')

@section('title', trans('vouchers::admin.codes.create'))

@section('content')
    <form action="{{ route('vouchers.admin.codes.store') }}" method="POST">
        <div class="card shadow mb-4">
            <div class="card-body">
                @include('vouchers::admin.codes._form')

                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-save"></i> {{ trans('messages.actions.save') }}
                </button>
            </div>
        </div>
    </form>
@endsection
