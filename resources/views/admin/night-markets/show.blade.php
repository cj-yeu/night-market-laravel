@extends('layouts.app')

@section('title', $nightMarket->name.' | Admin')

@section('content')
    <div class="d-flex flex-column flex-md-row justify-content-between gap-3 mb-4">
        <div><h1 class="h2 fw-bold mb-1">{{ $nightMarket->name }}</h1><p class="text-secondary mb-0">Night market catalog details</p></div>
        <div class="d-flex gap-2 align-self-start">
            <a href="{{ route('admin.night-markets.index') }}" class="btn btn-outline-secondary">Back</a>
            <a href="{{ route('admin.night-markets.edit', $nightMarket) }}" class="btn btn-market">Edit</a>
        </div>
    </div>
    <div class="row g-4">
        <div class="col-12 col-lg-7"><div class="card market-card h-100"><div class="card-body p-4">
            <dl class="row mb-0">
                <dt class="col-sm-4">Status</dt><dd class="col-sm-8"><span class="badge {{ $nightMarket->status === 'active' ? 'text-bg-success' : 'text-bg-secondary' }}">{{ ucfirst($nightMarket->status) }}</span></dd>
                <dt class="col-sm-4">Address</dt><dd class="col-sm-8">{{ $nightMarket->address }}</dd>
                <dt class="col-sm-4">City / state</dt><dd class="col-sm-8">{{ $nightMarket->city }}, {{ $nightMarket->state }}</dd>
                <dt class="col-sm-4">Description</dt><dd class="col-sm-8">{{ $nightMarket->description ?: 'No description available.' }}</dd>
                <dt class="col-sm-4">Stalls</dt><dd class="col-sm-8">{{ $nightMarket->stalls_count }}</dd>
            </dl>
        </div></div></div>
        <div class="col-12 col-lg-5"><div class="card market-card h-100"><div class="card-body p-4">
            <h2 class="h5 fw-bold">Operating schedule</h2>
            @foreach ($nightMarket->operatingDays as $day)
                <div class="d-flex justify-content-between border-bottom py-2"><span>{{ $day->day_of_week }}</span><span>{{ $day->opening_time->format('H:i') }}–{{ $day->closing_time->format('H:i') }}</span></div>
            @endforeach
        </div></div></div>
    </div>
@endsection
