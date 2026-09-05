@extends('layouts.app')

@section('title', 'Edit Visit Plan | '.config('app.name'))

@section('content')
    <header class="mb-4">
        <h1 class="h2 fw-bold text-market">Edit Visit Plan</h1>
        <p class="text-secondary">Update your visit details and check the regular market schedule.</p>
    </header>
    @if ($nightMarkets->isEmpty())
        <div class="alert alert-warning">No active Night Markets with an operating schedule are currently available for planning.</div>
    @else
        @include('client.visit-plans._form')
    @endif
@endsection
