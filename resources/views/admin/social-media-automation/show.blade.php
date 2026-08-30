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

    <section class="mt-4" aria-labelledby="catalog-suggestions-heading">
        <div class="card market-card">
            <div class="card-body p-4">
                <h2 id="catalog-suggestions-heading" class="h3 text-market">AI Catalog Suggestions</h2>
                <p class="text-secondary">Gemini analyzes only the fetched video title and description. These are AI-generated draft suggestions. Review all information before any future import.</p>

                @if ($proposal->status === \App\Models\CatalogImportProposal::STATUS_DRAFT && $proposal->socialMediaSource->metadata_status === \App\Models\SocialMediaSource::METADATA_FETCHED)
                    <form method="POST" action="{{ route('admin.social-media.automation.proposals.generate-suggestions', $proposal) }}">
                        @csrf
                        <button type="submit" class="btn btn-market">
                            {{ $proposal->extraction_status === \App\Models\CatalogImportProposal::EXTRACTION_COMPLETED ? 'Regenerate Suggestions' : 'Generate Suggestions' }}
                        </button>
                    </form>
                @else
                    <p class="mb-0 text-secondary">Fetch official metadata and keep this proposal as a draft before generating suggestions.</p>
                @endif

                <dl class="row mb-0 mt-4">
                    <dt class="col-sm-3">Extraction status</dt>
                    <dd class="col-sm-9"><span class="badge text-bg-secondary">{{ ucfirst($proposal->extraction_status) }}</span></dd>
                    @if ($proposal->extraction_status === \App\Models\CatalogImportProposal::EXTRACTION_FAILED)
                        <dt class="col-sm-3">Safe status</dt>
                        <dd class="col-sm-9">{{ $extractionFailureMessage }}</dd>
                    @endif
                    @if ($proposal->extraction_model)
                        <dt class="col-sm-3">Extraction model</dt>
                        <dd class="col-sm-9">{{ $proposal->extraction_model }}</dd>
                    @endif
                    @if ($proposal->extracted_at)
                        <dt class="col-sm-3">Extracted</dt>
                        <dd class="col-sm-9">{{ $proposal->extracted_at->format('d M Y, H:i') }}</dd>
                    @endif
                </dl>
            </div>
        </div>

        @if ($proposal->proposalMarket)
            <div class="card market-card mt-4">
                <div class="card-body p-4">
                    <h3 class="h4 text-market">Proposed Market</h3>
                    @if ($proposal->target_type !== \App\Models\CatalogImportProposal::TARGET_NEW_MARKET)
                        <div class="alert alert-info mb-3" role="status">
                            This is the Admin-selected Market identity and is locked. No production record is changed.
                        </div>
                    @endif

                    @if ($proposal->target_type === \App\Models\CatalogImportProposal::TARGET_NEW_MARKET)
                        <form method="POST" action="{{ route('admin.social-media.automation.proposals.market.update', [$proposal, $proposal->proposalMarket]) }}" class="row g-3">
                            @csrf
                            @method('PATCH')
                            <div class="col-md-6">
                                <label class="form-label" for="proposal-market-name">Name</label>
                                <input id="proposal-market-name" name="name" class="form-control" value="{{ old('name', $proposal->proposalMarket->name) }}" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="proposal-market-state">State</label>
                                <input id="proposal-market-state" name="state" class="form-control" value="{{ old('state', $proposal->proposalMarket->state) }}">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="proposal-market-address">Address</label>
                                <input id="proposal-market-address" name="address" class="form-control" value="{{ old('address', $proposal->proposalMarket->address) }}">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="proposal-market-city">City</label>
                                <input id="proposal-market-city" name="city" class="form-control" value="{{ old('city', $proposal->proposalMarket->city) }}">
                            </div>
                            <div class="col-12">
                                <label class="form-label" for="proposal-market-description">Description</label>
                                <textarea id="proposal-market-description" name="description" class="form-control" rows="2">{{ old('description', $proposal->proposalMarket->description) }}</textarea>
                            </div>
                            <div class="col-md-8">
                                <label class="form-label" for="proposal-market-evidence">Source evidence</label>
                                <input id="proposal-market-evidence" name="evidence_text" class="form-control" value="{{ old('evidence_text', $proposal->proposalMarket->evidence_text) }}">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="proposal-market-confidence">Confidence (informational)</label>
                                <input id="proposal-market-confidence" name="confidence" type="number" min="0" max="100" step="0.01" class="form-control" value="{{ old('confidence', $proposal->proposalMarket->confidence) }}">
                            </div>
                            <div class="col-12"><button class="btn btn-outline-secondary" type="submit">Save Market Draft</button></div>
                        </form>
                    @else
                        <dl class="row mb-0">
                            <dt class="col-sm-3">Name</dt><dd class="col-sm-9">{{ $proposal->proposalMarket->name }}</dd>
                            <dt class="col-sm-3">Location</dt><dd class="col-sm-9">{{ collect([$proposal->proposalMarket->address, $proposal->proposalMarket->city, $proposal->proposalMarket->state])->filter()->implode(', ') }}</dd>
                        </dl>
                    @endif
                </div>
            </div>

            <div class="card market-card mt-4">
                <div class="card-body p-4">
                    <h3 class="h4 text-market">Proposed Operating Days</h3>
                    @if ($proposal->proposalMarket->operatingDays->isEmpty())
                        <p class="mb-0 text-secondary">No source-supported operating-day suggestions were available.</p>
                    @else
                        @foreach ($proposal->proposalMarket->operatingDays as $operatingDay)
                            <details class="border rounded p-3 mb-3">
                                <summary class="fw-semibold">{{ $operatingDay->day_of_week }} · {{ $operatingDay->opening_time?->format('H:i') ?? 'Time not available' }}–{{ $operatingDay->closing_time?->format('H:i') ?? 'Time not available' }}</summary>
                                <form method="POST" action="{{ route('admin.social-media.automation.proposals.operating-days.update', [$proposal, $operatingDay]) }}" class="row g-3 mt-1">
                                    @csrf @method('PATCH')
                                    <div class="col-md-4"><label class="form-label">Day</label><select class="form-select" name="day_of_week">@foreach (\App\Models\MarketOperatingDay::DAYS as $day)<option value="{{ $day }}" @selected($operatingDay->day_of_week === $day)>{{ $day }}</option>@endforeach</select></div>
                                    <div class="col-md-4"><label class="form-label">Opening time</label><input class="form-control" type="time" name="opening_time" value="{{ $operatingDay->opening_time?->format('H:i') }}"></div>
                                    <div class="col-md-4"><label class="form-label">Closing time</label><input class="form-control" type="time" name="closing_time" value="{{ $operatingDay->closing_time?->format('H:i') }}"></div>
                                    <div class="col-md-8"><label class="form-label">Source evidence</label><input class="form-control" name="evidence_text" value="{{ $operatingDay->evidence_text }}"></div>
                                    <div class="col-md-4"><label class="form-label">Confidence</label><input class="form-control" type="number" min="0" max="100" step="0.01" name="confidence" value="{{ $operatingDay->confidence }}"></div>
                                    <div class="col-12"><button class="btn btn-outline-secondary" type="submit">Save</button></div>
                                </form>
                                <form method="POST" action="{{ route('admin.social-media.automation.proposals.operating-days.destroy', [$proposal, $operatingDay]) }}" class="mt-2">@csrf @method('DELETE')<button class="btn btn-outline-danger" type="submit">Remove</button></form>
                            </details>
                        @endforeach
                    @endif
                </div>
            </div>

            <div class="card market-card mt-4">
                <div class="card-body p-4">
                    <h3 class="h4 text-market">Proposed Stalls and Foods</h3>
                    @if ($proposal->proposalMarket->stalls->isEmpty())
                        <p class="mb-0 text-secondary">No source-supported Stall or Food suggestions were available.</p>
                    @else
                        @foreach ($proposal->proposalMarket->stalls as $stall)
                            <div class="border rounded p-3 mb-4">
                                <div class="d-flex justify-content-between gap-3 align-items-start">
                                    <div><h4 class="h5 mb-1">{{ $stall->name }}</h4><p class="small text-secondary mb-2">Halal status: {{ \App\Models\Stall::halalStatusOptions()[$stall->halal_status] ?? 'Unknown' }}</p></div>
                                    @if ($stall->matched_stall_id === null)
                                        <form method="POST" action="{{ route('admin.social-media.automation.proposals.stalls.destroy', [$proposal, $stall]) }}">@csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger" type="submit">Remove Stall Draft</button></form>
                                    @endif
                                </div>
                                @if ($stall->matched_stall_id !== null)
                                    <p class="text-secondary small">This is the Admin-selected Stall identity and is locked.</p>
                                @else
                                    <details class="mb-3"><summary>Edit Stall Draft</summary><form method="POST" action="{{ route('admin.social-media.automation.proposals.stalls.update', [$proposal, $stall]) }}" class="row g-3 mt-1">@csrf @method('PATCH')
                                        <div class="col-md-6"><label class="form-label">Name</label><input class="form-control" name="name" value="{{ $stall->name }}" required></div>
                                        <div class="col-md-6"><label class="form-label">Halal status</label><select class="form-select" name="halal_status">@foreach (\App\Models\Stall::halalStatusOptions() as $value => $label)<option value="{{ $value }}" @selected($stall->halal_status === $value)>{{ $label }}</option>@endforeach</select></div>
                                        <div class="col-12"><label class="form-label">Description</label><textarea class="form-control" name="description" rows="2">{{ $stall->description }}</textarea></div>
                                        <div class="col-md-8"><label class="form-label">Source evidence</label><input class="form-control" name="evidence_text" value="{{ $stall->evidence_text }}"></div>
                                        <div class="col-md-4"><label class="form-label">Confidence</label><input class="form-control" type="number" min="0" max="100" step="0.01" name="confidence" value="{{ $stall->confidence }}"></div>
                                        <div class="col-12"><button class="btn btn-outline-secondary" type="submit">Save Stall Draft</button></div>
                                    </form></details>
                                @endif

                                <h5 class="h6 text-market">Proposed Foods</h5>
                                @if ($stall->foods->isEmpty())
                                    <p class="small text-secondary mb-0">No source-supported Food suggestions were available.</p>
                                @else
                                    @foreach ($stall->foods as $food)
                                        <details class="border rounded p-3 mb-2">
                                            <summary class="fw-semibold">{{ $food->name }}@if ($food->price_display) · {{ $food->price_display }}@endif</summary>
                                            <form method="POST" action="{{ route('admin.social-media.automation.proposals.foods.update', [$proposal, $food]) }}" class="row g-3 mt-1">@csrf @method('PATCH')
                                                <div class="col-md-6"><label class="form-label">Name</label><input class="form-control" name="name" value="{{ $food->name }}" required></div>
                                                <div class="col-md-6"><label class="form-label">Category</label><input class="form-control" name="category" value="{{ $food->category }}"></div>
                                                <div class="col-12"><label class="form-label">Description</label><textarea class="form-control" name="description" rows="2">{{ $food->description }}</textarea></div>
                                                <div class="col-md-4"><label class="form-label">Price display</label><input class="form-control" name="price_display" value="{{ $food->price_display }}"></div>
                                                <div class="col-md-4"><label class="form-label">Minimum price</label><input class="form-control" type="number" min="0" step="0.01" name="price_min" value="{{ $food->price_min }}"></div>
                                                <div class="col-md-4"><label class="form-label">Maximum price</label><input class="form-control" type="number" min="0" step="0.01" name="price_max" value="{{ $food->price_max }}"></div>
                                                <div class="col-md-8"><label class="form-label">Source evidence</label><input class="form-control" name="evidence_text" value="{{ $food->evidence_text }}"></div>
                                                <div class="col-md-4"><label class="form-label">Confidence</label><input class="form-control" type="number" min="0" max="100" step="0.01" name="confidence" value="{{ $food->confidence }}"></div>
                                                <div class="col-12 form-check ms-2"><input class="form-check-input" type="checkbox" value="1" name="is_must_try" id="food-must-try-{{ $food->id }}" @checked($food->is_must_try)><label class="form-check-label" for="food-must-try-{{ $food->id }}">Must-Try (Admin review only)</label></div>
                                                <div class="col-12"><button class="btn btn-outline-secondary" type="submit">Save Food Draft</button></div>
                                            </form>
                                            <form method="POST" action="{{ route('admin.social-media.automation.proposals.foods.destroy', [$proposal, $food]) }}" class="mt-2">@csrf @method('DELETE')<button class="btn btn-outline-danger" type="submit">Remove Food Draft</button></form>
                                        </details>
                                    @endforeach
                                @endif
                            </div>
                        @endforeach
                    @endif
                    @if ($proposal->proposalMarket->operatingDays->isEmpty() || $proposal->proposalMarket->stalls->isEmpty())
                        <p class="small text-secondary mb-0">Some information was not available in the fetched source text. No missing details were invented.</p>
                    @endif
                </div>
            </div>
        @endif
    </section>
@endsection
