@extends('layouts.app')

@section('title', 'Review '.$nightMarket->name.' | Night Market Selangor')

@section('content')
    <div class="row justify-content-center"><div class="col-12 col-lg-7">
        <a href="{{ route('night-markets.show', $nightMarket) }}" class="btn btn-outline-secondary mb-4">Back to Night Market</a>
        <div class="card market-card"><div class="card-body p-4 p-md-5">
            <h1 class="h3 fw-bold text-market">Review {{ $nightMarket->name }}</h1>
            <p class="text-secondary mb-4">Your review will be published immediately.</p>
            <form method="POST" action="{{ route('client.night-markets.reviews.store', $nightMarket) }}" novalidate>
                @csrf
                @include('client.reviews.partials.fields', ['submitLabel' => 'Publish Market Review', 'cancelUrl' => route('night-markets.show', $nightMarket)])
            </form>
        </div></div>
    </div></div>
@endsection
