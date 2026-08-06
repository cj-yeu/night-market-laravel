@extends('layouts.app')

@section('title', $nightMarket->name.' | Night Market Selangor')

@section('content')
    <div class="row justify-content-center">
        <div class="col-12 col-xl-9">
            <a href="{{ route('client.night-markets.index') }}"
                class="btn btn-outline-secondary mb-4">Back to Night Markets</a>

            <div class="card market-card mb-4">
                <div class="card-body p-4 p-md-5">
                    <span class="badge text-bg-warning mb-3">{{ $nightMarket->city }}</span>
                    <h1 class="display-6 fw-bold text-market">{{ $nightMarket->name }}</h1>

                    <a href="{{ route('client.night-markets.stalls.index', $nightMarket->id) }}"
                        class="btn btn-market mt-3">Browse Stalls &amp; Must-Try Foods</a>

                    <dl class="row mt-4 mb-0">
                        <dt class="col-sm-3">Address</dt>
                        <dd class="col-sm-9">{{ $nightMarket->address }}</dd>

                        <dt class="col-sm-3">District</dt>
                        <dd class="col-sm-9">{{ $nightMarket->city }}, {{ $nightMarket->state }}</dd>

                        <dt class="col-sm-3">Description</dt>
                        <dd class="col-sm-9 mb-0">{{ $nightMarket->description ?: 'No description available.' }}</dd>
                    </dl>
                </div>
            </div>

            <div class="card market-card">
                <div class="card-body p-4 p-md-5">
                    <h2 class="h4 fw-bold text-market mb-4">Operating Hours</h2>

                    @if ($nightMarket->operatingDays->isEmpty())
                        <div class="alert alert-secondary mb-0">Operating hours are not available yet.</div>
                    @else
                        <div class="table-responsive">
                            <table class="table table-striped align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th scope="col">Operating Day</th>
                                        <th scope="col">Opening Time</th>
                                        <th scope="col">Closing Time</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($nightMarket->operatingDays as $operatingDay)
                                        <tr>
                                            <td>{{ $operatingDay->day_of_week }}</td>
                                            <td>{{ $operatingDay->opening_time->format('g:i A') }}</td>
                                            <td>{{ $operatingDay->closing_time->format('g:i A') }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection