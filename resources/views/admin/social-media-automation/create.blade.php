@extends('layouts.app')

@section('title', 'Create Automation Import Draft | Night Market Selangor')

@section('content')
    <div class="row justify-content-center">
        <div class="col-12 col-xl-9">
            <div class="card market-card">
                <div class="card-body p-4 p-md-5">
                    <h1 class="h3 fw-bold text-market">Create Automation Import Draft</h1>
                    <p class="text-secondary">
                        This accepts a YouTube video URL only. Metadata will not be fetched and no catalog record will be
                        created in this phase.
                    </p>

                    @if ($errors->any())
                        <div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>
                    @endif

                    <form method="POST" action="{{ route('admin.social-media.automation.store') }}" novalidate>
                        @csrf

                        <div class="mb-3">
                            <label for="youtube_url" class="form-label">YouTube video URL</label>
                            <input type="url" id="youtube_url" name="youtube_url" maxlength="2048"
                                value="{{ old('youtube_url') }}"
                                class="form-control @error('youtube_url') is-invalid @enderror"
                                placeholder="https://www.youtube.com/watch?v=VIDEO_ID" required>
                            <div class="form-text">Supported: watch, youtu.be, shorts, and embed video links over HTTPS.</div>
                            @error('youtube_url')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <fieldset class="mb-3">
                            <legend class="form-label">Proposal target</legend>
                            <select id="target_type" name="target_type"
                                class="form-select @error('target_type') is-invalid @enderror" required>
                                <option value="">Select a target</option>
                                <option value="{{ \App\Models\CatalogImportProposal::TARGET_EXISTING_MARKET }}"
                                    @selected(old('target_type') === \App\Models\CatalogImportProposal::TARGET_EXISTING_MARKET)>
                                    Existing Market
                                </option>
                                <option value="{{ \App\Models\CatalogImportProposal::TARGET_EXISTING_STALL }}"
                                    @selected(old('target_type') === \App\Models\CatalogImportProposal::TARGET_EXISTING_STALL)>
                                    Existing Stall
                                </option>
                                <option value="{{ \App\Models\CatalogImportProposal::TARGET_NEW_MARKET }}"
                                    @selected(old('target_type') === \App\Models\CatalogImportProposal::TARGET_NEW_MARKET)>
                                    New Market
                                </option>
                            </select>
                            @error('target_type')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                        </fieldset>

                        <div id="existing-market-field" class="mb-3" hidden>
                            <label for="matched_night_market_id" class="form-label">Eligible existing Market</label>
                            <select id="matched_night_market_id" name="matched_night_market_id"
                                class="form-select @error('matched_night_market_id') is-invalid @enderror">
                                <option value="">Select an active Selangor Market</option>
                                @foreach ($nightMarkets as $market)
                                    <option value="{{ $market->id }}"
                                        @selected((string) old('matched_night_market_id') === (string) $market->id)>
                                        {{ $market->name }} — {{ $market->city }}
                                        @if ($market->active_stalls_count === 0)
                                            (no active stalls)
                                        @endif
                                    </option>
                                @endforeach
                            </select>
                            <div class="form-text">Markets without Active Stalls are prioritised but not required.</div>
                            @error('matched_night_market_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div id="existing-stall-field" class="mb-3" hidden>
                            <label for="matched_stall_id" class="form-label">Eligible existing Stall</label>
                            <select id="matched_stall_id" name="matched_stall_id"
                                class="form-select @error('matched_stall_id') is-invalid @enderror">
                                <option value="">Select an active Stall in Selangor</option>
                                @foreach ($stalls as $stall)
                                    <option value="{{ $stall->id }}"
                                        @selected((string) old('matched_stall_id') === (string) $stall->id)>
                                        {{ $stall->name }} — {{ $stall->nightMarket->name }}
                                        @if ($stall->active_foods_count === 0)
                                            (no active foods)
                                        @endif
                                    </option>
                                @endforeach
                            </select>
                            <div class="form-text">Stalls without Active Foods are prioritised but not required.</div>
                            @error('matched_stall_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div id="new-market-note" class="alert alert-info" role="status" hidden>
                            A new Market target creates only a draft proposal. It does not create a Night Market.
                        </div>

                        <div class="d-flex flex-wrap gap-2 mt-4">
                            <button type="submit" class="btn btn-market">Save Draft Proposal</button>
                            <a href="{{ route('admin.social-media.automation.index') }}" class="btn btn-outline-secondary">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const target = document.getElementById('target_type');
            const marketField = document.getElementById('existing-market-field');
            const stallField = document.getElementById('existing-stall-field');
            const newMarketNote = document.getElementById('new-market-note');

            const updateTargetFields = () => {
                marketField.hidden = target.value !== 'existing_market';
                stallField.hidden = target.value !== 'existing_stall';
                newMarketNote.hidden = target.value !== 'new_market';
            };

            target.addEventListener('change', updateTargetFields);
            updateTargetFields();
        });
    </script>
@endpush
