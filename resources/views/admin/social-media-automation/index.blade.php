@extends('layouts.app')

@section('title', 'Automation Imports | Night Market Selangor')

@section('content')
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
        <div>
            <h1 class="display-6 fw-bold text-market mb-1">Automation Imports</h1>
            <p class="text-secondary mb-0">Prepare reviewed catalog-import drafts from safe social-media sources.</p>
        </div>
        <a href="{{ route('admin.social-media.automation.create') }}" class="btn btn-market">
            Create Draft Proposal
        </a>
    </div>

    @if (session('status'))
        <div class="alert alert-success" role="status">{{ session('status') }}</div>
    @endif

    <div class="row g-4 mb-4">
        <section class="col-12 col-xl-6" aria-labelledby="markets-without-stalls-heading">
            <div class="card market-card h-100">
                <div class="card-body p-4">
                    <h2 id="markets-without-stalls-heading" class="h4 text-market">Markets without Active Stalls</h2>
                    <p class="text-secondary">{{ $marketGaps->count() }} active Selangor market(s) need stall data.</p>
                    @if ($marketGaps->isEmpty())
                        <p class="mb-0 text-secondary">No active Selangor markets currently have this gap.</p>
                    @else
                        <ul class="list-group list-group-flush">
                            @foreach ($marketGaps as $market)
                                <li class="list-group-item px-0 d-flex justify-content-between gap-3">
                                    <span>{{ $market->name }}</span>
                                    <span class="small text-secondary text-nowrap">{{ $market->city }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>
        </section>

        <section class="col-12 col-xl-6" aria-labelledby="stalls-without-foods-heading">
            <div class="card market-card h-100">
                <div class="card-body p-4">
                    <h2 id="stalls-without-foods-heading" class="h4 text-market">Stalls without Active Foods</h2>
                    <p class="text-secondary">{{ $stallGaps->count() }} eligible active stall(s) need food data.</p>
                    @if ($stallGaps->isEmpty())
                        <p class="mb-0 text-secondary">No eligible active stalls currently have this gap.</p>
                    @else
                        <ul class="list-group list-group-flush">
                            @foreach ($stallGaps as $stall)
                                <li class="list-group-item px-0">
                                    <div>{{ $stall->name }}</div>
                                    <div class="small text-secondary">{{ $stall->nightMarket->name }}</div>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>
        </section>
    </div>

    <section aria-labelledby="automation-proposals-heading">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h2 id="automation-proposals-heading" class="h3 fw-bold text-market mb-0">Import Proposals</h2>
            <span class="text-secondary small">Draft, submitted, and import history</span>
        </div>

        @if ($proposals->isEmpty())
            <div class="alert alert-info" role="status">
                No automation import proposals exist yet. Create a draft to begin a later review workflow.
            </div>
        @else
            <div class="card market-card">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Source</th>
                                <th>Target</th>
                                <th>Status</th>
                                <th>Created by</th>
                                <th>Created</th>
                                <th><span class="visually-hidden">Actions</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($proposals as $proposal)
                                <tr>
                                    <td>
                                        <div class="fw-semibold">{{ ucfirst($proposal->socialMediaSource->platform) }}</div>
                                        <a href="{{ $proposal->socialMediaSource->canonical_url }}" target="_blank"
                                            rel="noopener noreferrer" class="small text-break">View video source</a>
                                        <div class="mt-1">
                                            <span class="badge text-bg-light border text-secondary">Metadata: {{ ucfirst($proposal->socialMediaSource->metadata_status) }}</span>
                                        </div>
                                    </td>
                                    <td>
                                        @if ($proposal->target_type === \App\Models\CatalogImportProposal::TARGET_EXISTING_MARKET)
                                            Existing Market: {{ $proposal->matchedNightMarket?->name ?? 'Unavailable market' }}
                                        @elseif ($proposal->target_type === \App\Models\CatalogImportProposal::TARGET_EXISTING_STALL)
                                            Existing Stall: {{ $proposal->matchedStall?->name ?? 'Unavailable stall' }}
                                        @else
                                            New Market proposal
                                        @endif
                                    </td>
                                    <td>
                                        <span class="badge text-bg-secondary">{{ ucfirst($proposal->status) }}</span>
                                        @if ($proposal->submitted_at)
                                            <div class="small text-secondary mt-1">Submitted {{ $proposal->submitted_at->format('d M Y') }}</div>
                                        @endif
                                    </td>
                                    <td>{{ $proposal->createdBy->name }}</td>
                                    <td>{{ $proposal->created_at->format('d M Y') }}</td>
                                    <td class="text-end">
                                        <a class="btn btn-sm btn-outline-secondary"
                                            href="{{ route('admin.social-media.automation.show', $proposal) }}">View proposal</a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            @if ($proposals->hasPages())
                <nav class="d-flex justify-content-between align-items-center mt-4" aria-label="Automation imports pagination">
                    <a class="btn btn-outline-secondary {{ $proposals->onFirstPage() ? 'disabled' : '' }}"
                        href="{{ $proposals->previousPageUrl() ?: '#' }}">Previous</a>
                    <span class="text-secondary">Page {{ $proposals->currentPage() }} of {{ $proposals->lastPage() }}</span>
                    <a class="btn btn-outline-secondary {{ $proposals->hasMorePages() ? '' : 'disabled' }}"
                        href="{{ $proposals->nextPageUrl() ?: '#' }}">Next</a>
                </nav>
            @endif
        @endif
    </section>
@endsection
