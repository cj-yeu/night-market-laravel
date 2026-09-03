@extends('layouts.app')

@section('title', 'Review '.$food->name.' | '.config('app.name'))

@section('content')
    <div class="row justify-content-center">
        <div class="col-12 col-lg-7">
            <a href="{{ route('foods.show', $food) }}" class="btn btn-outline-secondary mb-4">Back to Food</a>
            <div class="card market-card">
                <div class="card-body p-4 p-md-5">
                    <h1 class="h3 fw-bold text-market">Review {{ $food->name }}</h1>
                    <p class="text-secondary mb-1">From {{ $food->stall->name }} at {{ $food->stall->nightMarket->name }}</p>
                    <p class="text-secondary mb-4">Your review will be published immediately.</p>
                    <form method="POST" action="{{ route('client.foods.reviews.store', $food) }}" novalidate>
                        @csrf
                        @include('client.reviews.partials.fields', ['submitLabel' => 'Publish Review'])
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
