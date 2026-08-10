@extends('admin.layouts.admin')

@section('title', trans('vouchers::admin.codes.title'))

@section('content')
    <div class="card shadow mb-4">
        <div class="card-body">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                <p class="text-muted mb-0">{{ trans('vouchers::admin.codes.description') }}</p>

                <a class="btn btn-primary" href="{{ route('vouchers.admin.codes.create') }}">
                    <i class="bi bi-plus-lg"></i> {{ trans('vouchers::admin.codes.create') }}
                </a>
            </div>

            @if($vouchers->isEmpty())
                <div class="alert alert-info mb-0" role="alert">
                    <i class="bi bi-info-circle"></i> {{ trans('vouchers::admin.codes.empty') }}
                </div>
            @else
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th scope="col">{{ trans('vouchers::admin.fields.name') }}</th>
                                <th scope="col">{{ trans('vouchers::admin.fields.code') }}</th>
                                <th scope="col">{{ trans('vouchers::admin.fields.status') }}</th>
                                <th scope="col">{{ trans('vouchers::admin.fields.uses') }}</th>
                                <th scope="col">{{ trans('vouchers::admin.fields.rewards') }}</th>
                                <th scope="col" class="text-end">{{ trans('messages.fields.action') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($vouchers as $voucher)
                                @php
                                    $status = $voucher->availabilityStatusAt(now());
                                @endphp
                                <tr>
                                    <td>{{ $voucher->name }}</td>
                                    <td><code>{{ $voucher->code }}</code></td>
                                    <td>
                                        <span class="badge bg-{{ trans('vouchers::admin.status.'.$status.'.color') }}">
                                            {{ trans('vouchers::admin.status.'.$status.'.label') }}
                                        </span>
                                    </td>
                                    <td>
                                        {{ $voucher->redemptions_count }} /
                                        {{ $voucher->max_redemptions ?? trans('vouchers::admin.unlimited') }}
                                    </td>
                                    <td>{{ $voucher->rewards_count }}</td>
                                    <td class="text-end text-nowrap">
                                        <a href="{{ route('vouchers.admin.codes.edit', $voucher) }}" class="m-1" title="{{ trans('messages.actions.edit') }}" aria-label="{{ trans('messages.actions.edit') }}" data-bs-toggle="tooltip">
                                            <i class="bi bi-pencil-square"></i>
                                        </a>
                                        @if($voucher->is_enabled)
                                            <form action="{{ route('vouchers.admin.codes.disable', $voucher) }}" method="POST" class="d-inline">
                                                @csrf
                                                @method('PATCH')
                                                <button type="submit" class="btn btn-link link-warning m-1 p-0 align-baseline" title="{{ trans('vouchers::admin.actions.disable') }}" aria-label="{{ trans('vouchers::admin.actions.disable') }}" data-bs-toggle="tooltip">
                                                    <i class="bi bi-pause-circle"></i>
                                                </button>
                                            </form>
                                        @endif
                                        <a href="{{ route('vouchers.admin.codes.destroy', $voucher) }}" class="m-1" title="{{ trans('messages.actions.delete') }}" aria-label="{{ trans('messages.actions.delete') }}" data-bs-toggle="tooltip" data-confirm="delete">
                                            <i class="bi bi-trash"></i>
                                        </a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="mt-3">
                    {{ $vouchers->links() }}
                </div>
            @endif
        </div>
    </div>
@endsection
