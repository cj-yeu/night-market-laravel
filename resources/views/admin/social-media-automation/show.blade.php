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

    @if ($proposal->socialMediaSource->metadata_status === \App\Models\SocialMediaSource::METADATA_FETCHED)
        <div class="alert alert-success" role="status">
            <strong>Official YouTube metadata was retrieved.</strong> This proposal remains a draft; no Night Market, Stall, or Food record has been created.
        </div>
    @elseif ($proposal->socialMediaSource->metadata_status === \App\Models\SocialMediaSource::METADATA_FAILED)
        <div class="alert alert-warning" role="status">
            <strong>Metadata retrieval needs attention.</strong> {{ $metadataFailureMessage }} No Night Market, Stall, or Food record has been created.
        </div>
    @else
        <div class="alert alert-info" role="status">
            <strong>Metadata has not been fetched yet.</strong> No Night Market, Stall, or Food record has been created.
            This is a draft for a later automation phase.
        </div>
    @endif

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

                @if ($proposal->socialMediaSource->thumbnail_url)
                    <dt class="col-sm-3">Video thumbnail</dt>
                    <dd class="col-sm-9">
                        <img src="{{ $proposal->socialMediaSource->thumbnail_url }}"
                            alt="{{ $proposal->socialMediaSource->title ?? 'YouTube video thumbnail' }}"
                            class="img-fluid rounded border" style="max-width: 320px" referrerpolicy="no-referrer">
                    </dd>
                @endif

                @if ($proposal->socialMediaSource->title)
                    <dt class="col-sm-3">Video title</dt>
                    <dd class="col-sm-9">{{ $proposal->socialMediaSource->title }}</dd>
                @endif

                @if ($proposal->socialMediaSource->creator_name)
                    <dt class="col-sm-3">Channel</dt>
                    <dd class="col-sm-9">{{ $proposal->socialMediaSource->creator_name }}</dd>
                @endif

                @if ($proposal->socialMediaSource->published_at)
                    <dt class="col-sm-3">Published</dt>
                    <dd class="col-sm-9">{{ $proposal->socialMediaSource->published_at->format('d M Y') }}</dd>
                @endif

                @if ($proposal->socialMediaSource->description_excerpt)
                    <dt class="col-sm-3">Description excerpt</dt>
                    <dd class="col-sm-9 text-break">{{ $proposal->socialMediaSource->description_excerpt }}</dd>
                @endif

                <dt class="col-sm-3">Last metadata check</dt>
                <dd class="col-sm-9">{{ $proposal->socialMediaSource->metadata_fetched_at?->format('d M Y, H:i') ?? 'Not checked yet' }}</dd>

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

            <div class="border-top mt-4 pt-3">
                @if ($metadataIsFresh)
                    <p class="mb-0 text-secondary">Metadata is current and will be refreshed after 24 hours if needed.</p>
                @else
                    <form method="POST" action="{{ route('admin.social-media.automation.sources.fetch-metadata', $proposal->socialMediaSource) }}">
                        @csrf
                        <button type="submit" class="btn btn-outline-secondary">
                            {{ $proposal->socialMediaSource->metadata_status === \App\Models\SocialMediaSource::METADATA_FAILED ? 'Retry Metadata' : 'Fetch Metadata' }}
                        </button>
                    </form>
                @endif
            </div>
        </div>
    </div>
@endsection
