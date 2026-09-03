@extends('layouts.app')

@section('title', 'Edit Review for '.$nightMarket->name.' | '.config('app.name'))

@section('content')
    <div class="row justify-content-center"><div class="col-12 col-lg-7">
        <a href="{{ route('night-markets.show', $nightMarket) }}" class="btn btn-outline-secondary mb-4">Back to Night Market</a>
        <div class="card market-card"><div class="card-body p-4 p-md-5">
            <h1 class="h3 fw-bold text-market">Edit My Market Review</h1>
            <p class="text-secondary mb-4">Your changes remain publicly visible immediately.</p>
            <form method="POST" action="{{ route('client.night-markets.reviews.update', [$nightMarket, $review]) }}" novalidate>
                @csrf
                @method('PATCH')
                @include('client.reviews.partials.fields', ['submitLabel' => 'Update Market Review', 'cancelUrl' => route('night-markets.show', $nightMarket)])
            </form>
        </div></div>
    </div></div>
@endsection
