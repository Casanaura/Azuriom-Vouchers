@extends('admin.layouts.admin')

@section('title', trans('vouchers::admin.title'))

@section('content')
    <div class="card shadow mb-4">
        <div class="card-body">
            <p class="mb-0">{{ trans('vouchers::admin.coming_soon') }}</p>
        </div>
    </div>
@endsection
