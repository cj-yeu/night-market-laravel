@extends('layouts.app')

@section('title', 'Edit Review for '.$food->name.' | Night Market Selangor')

@section('content')
    <div class="row justify-content-center">
        <div class="col-12 col-lg-7">
            <a href="{{ route('foods.show', $food) }}" class="btn btn-outline-secondary mb-4">Back to Food</a>
            <div class="card market-card">
                <div class="card-body p-4 p-md-5">
                    <h1 class="h3 fw-bold text-market">Edit My Review</h1>
                    <p class="text-secondary mb-1">{{ $food->name }} from {{ $food->stall->name }}</p>
                    <p class="text-secondary mb-4">Your changes remain publicly visible immediately.</p>
                    <form method="POST" action="{{ route('client.foods.reviews.update', [$food, $review]) }}" novalidate>
                        @csrf
                        @method('PATCH')
                        @include('client.reviews.partials.fields', ['submitLabel' => 'Update Review'])
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
