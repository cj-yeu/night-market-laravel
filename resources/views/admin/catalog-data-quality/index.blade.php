@extends('layouts.app')

@section('title', 'Catalog Data Quality | Admin')

@section('content')
    <div class="mx-auto" style="max-width: 1100px;">
        <header class="mb-4">
            <h1 class="h2 fw-bold mb-1">Catalog Data Quality</h1>
            <p class="text-secondary mb-0">Review gaps before improving catalog records. This page never changes public status or halal classifications.</p>
        </header>
        <div class="row g-3">
            @foreach ($issues as $key => $issue)
                <div class="col-12 col-md-6 col-xl-4">
                    <a class="card market-card h-100 text-decoration-none text-body" href="{{ route('admin.catalog-data-quality.records', $key) }}">
                        <div class="card-body p-4">
                            <div class="small text-secondary mb-2">{{ $issue['label'] }}</div>
                            <div class="display-6 fw-bold text-market">{{ number_format($issue['count']) }}</div>
                            <span class="small text-market fw-semibold">Review records →</span>
                        </div>
                    </a>
                </div>
            @endforeach
        </div>
    </div>
@endsection
