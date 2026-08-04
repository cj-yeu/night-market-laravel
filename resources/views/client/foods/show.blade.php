@extends('layouts.app')

@section('title', $food->name.' | Night Market Selangor')

@section('content')
    <div class="row justify-content-center">
        <div class="col-12 col-lg-8">
            <a href="{{ route('client.night-markets.stalls.index', $food->stall->night_market_id) }}"
                class="btn btn-outline-secondary mb-4">Back to Stalls</a>

            <div class="card market-card">
                <div class="card-body p-4 p-md-5">
                    <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
                        @if ($food->category)
                            <span class="badge text-bg-secondary">{{ $food->category }}</span>
                        @endif
                        @if ($food->is_must_try)
                            <span class="badge text-bg-warning">Must-Try</span>
                        @endif
                    </div>

                    <h1 class="display-6 fw-bold text-market">{{ $food->name }}</h1>

                    <dl class="row mt-4 mb-0">
                        <dt class="col-sm-3">Stall</dt>
                        <dd class="col-sm-9">{{ $food->stall->name }}</dd>

                        <dt class="col-sm-3">Night Market</dt>
                        <dd class="col-sm-9">{{ $food->stall->nightMarket->name }}</dd>

                        <dt class="col-sm-3">Category</dt>
                        <dd class="col-sm-9">{{ $food->category ?: 'Not specified' }}</dd>

                        <dt class="col-sm-3">Description</dt>
                        <dd class="col-sm-9 mb-0">{{ $food->description ?: 'No description available.' }}</dd>
                    </dl>
                </div>
            </div>
        </div>
    </div>
@endsection