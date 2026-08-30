@extends('layouts.app')

@section('title', 'Automation Import Draft | Night Market Selangor')

@section('content')
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
        <div>
            <h1 class="display-6 fw-bold text-market mb-1">Automation Import Draft</h1>
            <p class="text-secondary mb-0">Draft #{{ $proposal->id }} · Revision {{ $proposal->revision }}</p>
        </div>
        <a href="{{ route('admin.social-media.automation.index') }}" class="btn btn-outline-secondary">Back to Imports</a>
    </div>

    @if (session('status'))
        <div class="alert alert-success" role="status">{{ session('status') }}</div>
    @endif

    <div class="alert alert-info" role="status">
        <strong>Metadata has not been fetched yet.</strong> No Night Market, Stall, or Food record has been created.
        This is a draft for a later automation phase.
    </div>

    <div class="card market-card">
        <div class="card-body p-4">
            <dl class="row mb-0">
                <dt class="col-sm-3">Source platform</dt>
                <dd class="col-sm-9">{{ ucfirst($proposal->socialMediaSource->platform) }}</dd>

                <dt class="col-sm-3">Canonical video URL</dt>
                <dd class="col-sm-9 text-break">
                    <a href="{{ $proposal->socialMediaSource->canonical_url }}" target="_blank" rel="noopener noreferrer">
                        {{ $proposal->socialMediaSource->canonical_url }}
                    </a>
                </dd>

                <dt class="col-sm-3">Metadata status</dt>
                <dd class="col-sm-9"><span class="badge text-bg-secondary">{{ ucfirst($proposal->socialMediaSource->metadata_status) }}</span></dd>

                <dt class="col-sm-3">Proposal target</dt>
                <dd class="col-sm-9">
                    @if ($proposal->target_type === \App\Models\CatalogImportProposal::TARGET_EXISTING_MARKET)
                        Existing Market: {{ $proposal->matchedNightMarket?->name ?? 'Unavailable market' }}
                    @elseif ($proposal->target_type === \App\Models\CatalogImportProposal::TARGET_EXISTING_STALL)
                        Existing Stall: {{ $proposal->matchedStall?->name ?? 'Unavailable stall' }}
                        @if ($proposal->matchedStall?->nightMarket)
                            <span class="text-secondary">({{ $proposal->matchedStall->nightMarket->name }})</span>
                        @endif
                    @else
                        New Market proposal
                    @endif
                </dd>

                <dt class="col-sm-3">Proposal status</dt>
                <dd class="col-sm-9"><span class="badge text-bg-secondary">{{ ucfirst($proposal->status) }}</span></dd>

                <dt class="col-sm-3">Created by</dt>
                <dd class="col-sm-9">{{ $proposal->createdBy->name }}</dd>
            </dl>
        </div>
    </div>
@endsection
