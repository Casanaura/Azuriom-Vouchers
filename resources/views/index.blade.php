@extends('layouts.app')

@section('title', trans('vouchers::messages.title'))

@section('content')
    <div class="card shadow-sm">
        <div class="card-body">
            <h1 class="h3">{{ trans('vouchers::messages.title') }}</h1>
            <p class="mb-0">{{ trans('vouchers::messages.coming_soon') }}</p>
        </div>
    </div>
@endsection
