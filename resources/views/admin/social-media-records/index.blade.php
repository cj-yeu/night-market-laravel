@extends('layouts.app')

@section('title', 'Social Media Records | '.config('app.name'))

@section('content')
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
        <div>
            <h1 class="display-6 fw-bold text-market mb-1">Social Media Records</h1>
            <p class="text-secondary mb-0">Manage manually collected public social media information.</p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a href="{{ route('admin.social-media.extract.create') }}" class="btn btn-market">
                Extract Public Post
            </a>
            <a href="{{ route('admin.social-media-records.create') }}" class="btn btn-outline-secondary">
                Add Social Media Record
            </a>
        </div>
    </div>

    @if ($errors->any())
        <div class="alert alert-danger" role="alert">
            {{ $errors->first() }}
        </div>
    @endif

    <section class="card market-card mb-4" aria-labelledby="platform-insights-heading">
        <div class="card-body p-4">
            <h2 id="platform-insights-heading" class="h4 text-market mb-3">Platform Insights</h2>
            @if ($platformInsights->isEmpty())
                <p class="text-secondary mb-0">No social media records are available for platform insights yet.</p>
            @else
                <div class="row g-3">
                    @foreach ($platformInsights as $insight)
                        <div class="col-12 col-md-6 col-xl-4">
                            <div class="border rounded-3 p-3 h-100">
                                <div class="d-flex justify-content-between gap-2 mb-2">
                                    <strong>{{ $insight->platform }}</strong>
                                    <span class="text-secondary small">{{ number_format($insight->record_count) }} records</span>
                                </div>
                                <div class="progress" role="progressbar" aria-label="{{ $insight->platform }} record share"
                                    aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $insight->percentage }}">
                                    <div class="progress-bar bg-market" style="width: {{ $insight->percentage }}%"></div>
                                </div>
                                <div class="small text-secondary mt-2">
                                    {{ number_format($insight->total_engagement) }} total engagement
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </section>

    <div class="card market-card mb-4">
        <div class="card-body p-4">
            <form method="GET" action="{{ route('admin.social-media-records.index') }}">
                <div class="row g-3 align-items-end">
                    <div class="col-12 col-xl-3">
                        <label for="search" class="form-label">Keyword Search</label>
                        <input type="search" id="search" name="search" maxlength="255"
                            value="{{ $filters['search'] ?? '' }}" class="form-control"
                            placeholder="Market, food, summary, or post URL">
                    </div>
                    <div class="col-md-6 col-xl-2">
                        <label for="night_market_id" class="form-label">Night Market</label>
                        <select id="night_market_id" name="night_market_id" class="form-select">
                            <option value="">All night markets</option>
                            @foreach ($nightMarkets as $nightMarket)
                                <option value="{{ $nightMarket->id }}"
                                    @selected((string) ($filters['night_market_id'] ?? '') === (string) $nightMarket->id)>
                                    {{ $nightMarket->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-6 col-xl-2">
                        <label for="platform" class="form-label">Platform</label>
                        <select id="platform" name="platform" class="form-select">
                            <option value="">All platforms</option>
                            @foreach ($platforms as $platform)
                                <option value="{{ $platform }}" @selected(($filters['platform'] ?? '') === $platform)>
                                    {{ $platform }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-6 col-xl-1">
                        <label for="status" class="form-label">Status</label>
                        <select id="status" name="status" class="form-select">
                            <option value="">All statuses</option>
                            @foreach ($statuses as $status)
                                <option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>
                                    {{ ucfirst($status) }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-6 col-xl-2">
                        <label for="posted_from" class="form-label">Posted From</label>
                        <input type="date" id="posted_from" name="posted_from"
                            value="{{ $filters['posted_from'] ?? '' }}" class="form-control">
                    </div>
                    <div class="col-md-6 col-xl-2">
                        <label for="posted_to" class="form-label">Posted To</label>
                        <input type="date" id="posted_to" name="posted_to"
                            value="{{ $filters['posted_to'] ?? '' }}" class="form-control">
                    </div>
                    <div class="col-md-6 col-xl-2 d-flex gap-2">
                        <button type="submit" class="btn btn-market">Filter</button>
                        @if (array_filter($filters))
                            <a href="{{ route('admin.social-media-records.index') }}"
                                class="btn btn-outline-secondary">Reset Filters</a>
                        @endif
                    </div>
                </div>
            </form>
        </div>
    </div>

    @if ($records->isEmpty())
        <div class="alert alert-info" role="alert">
            No social media records found. Add a record or adjust the current filters.
        </div>
    @else
        <form id="bulk-moderation-form" method="POST" action="{{ route('admin.social-media-records.bulk-moderate') }}"
            class="card market-card mb-3">
            @csrf
            @method('PATCH')
            <div class="card-body p-3 d-flex flex-column flex-lg-row align-items-lg-end gap-3">
                <div class="form-check mb-lg-2">
                    <input class="form-check-input" type="checkbox" id="select-pending-records">
                    <label class="form-check-label" for="select-pending-records">Select visible pending records</label>
                </div>
                <div class="flex-grow-1">
                    <label for="bulk-rejection-reason" class="form-label mb-1">Rejection reason (required only when rejecting)</label>
                    <input type="text" id="bulk-rejection-reason" name="rejection_reason" maxlength="500" class="form-control"
                        value="{{ old('rejection_reason') }}" placeholder="Explain why the selected records are unsuitable">
                </div>
                <div class="d-flex flex-wrap gap-2">
                    <button type="submit" name="action" value="approved" class="btn btn-success"
                        data-bulk-confirm="Approve the selected pending records for public viewing?">Approve Selected</button>
                    <button type="submit" name="action" value="rejected" class="btn btn-outline-danger"
                        data-bulk-confirm="Reject the selected pending records?">Reject Selected</button>
                </div>
            </div>
        </form>
        <p class="small text-secondary">Review pending records below. On smaller screens, scroll the table horizontally to reach all actions.</p>
        <div class="card market-card">
            <div class="table-responsive" tabindex="0" role="region" aria-label="Social media records">
                <table class="table table-hover align-middle mb-0 social-record-table">
                    <thead><tr><th><span class="visually-hidden">Select</span></th><th>Catalog context</th><th>Publication</th><th>Post details</th><th>Status</th><th>Actions</th></tr></thead>
                    <tbody>
                        @foreach ($records as $record)
                            <tr>
                                <td>
                                    @if ($record->status === \App\Models\SocialMediaRecord::STATUS_PENDING)
                                        <input class="form-check-input pending-record-checkbox" type="checkbox" form="bulk-moderation-form" name="record_ids[]" value="{{ $record->id }}" aria-label="Select social media record {{ $record->id }}">
                                    @endif
                                </td>
                                <td class="record-context"><strong>{{ $record->nightMarket?->name ?? 'Unavailable market' }}</strong><div class="small text-secondary">Food: {{ $record->food?->name ?? 'None' }}</div></td>
                                <td><span class="badge text-bg-warning">{{ $record->platform }}</span><div class="small mt-2">{{ ucfirst($record->extraction_status) }}</div><time class="small text-nowrap">{{ $record->posted_date->format('d M Y') }}</time></td>
                                <td>
                                    @if ($record->safe_source_url)<a href="{{ $record->safe_source_url }}" target="_blank" rel="noopener noreferrer">Open post <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i></a>@else<span class="text-secondary">URL unavailable</span>@endif
                                    <details><summary>Content and engagement</summary>
                                        @if ($record->extracted_title)<strong class="d-block">{{ $record->extracted_title }}</strong>@endif
                                        <p class="small">{{ \Illuminate\Support\Str::limit($record->content_summary, 220) }}</p>
                                        <dl class="small"><dt>Total Engagement</dt><dd>{{ number_format($record->engagement_count) }}</dd><dt>Likes / Comments / Shares</dt><dd>{{ number_format($record->likes) }} / {{ number_format($record->comments) }} / {{ number_format($record->shares) }}</dd><dt>Created</dt><dd>{{ $record->created_at->format('d M Y') }}</dd></dl>
                                        @foreach ($record->extracted_hashtags ?? [] as $hashtag)<span class="badge text-bg-light border">{{ $hashtag }}</span>@endforeach
                                        <div class="small">Markets: {{ implode(', ', $record->extracted_market_mentions ?? []) ?: 'None' }}</div>
                                        <div class="small">Foods: {{ implode(', ', $record->extracted_food_mentions ?? []) ?: 'None' }}</div>
                                        <div class="small">Locations: {{ implode(', ', $record->extracted_location_mentions ?? []) ?: 'None' }}</div>
                                    </details>
                                </td>
                                <td>
                                    <span class="badge {{ $record->status === 'approved' ? 'text-bg-success' : ($record->status === 'rejected' ? 'text-bg-danger' : 'text-bg-secondary') }}">{{ ucfirst($record->status) }}</span>
                                    @if ($record->status === 'rejected')
                                        <p class="small mt-2 mb-0"><strong>Reason:</strong> {{ $record->rejection_reason ?? 'Not recorded' }}<br>By {{ $record->rejectedBy?->name ?? 'Former administrator' }} @if ($record->rejected_at) on {{ $record->rejected_at->format('d M Y H:i') }} @endif</p>
                                    @endif
                                    @if ($record->status !== 'pending')<p class="small text-secondary mt-2">Editing returns this record to Pending for review.</p>@endif
                                </td>
                                <td class="record-actions">
                                    <div class="d-flex flex-wrap gap-2">
                                        <a href="{{ route('admin.social-media-records.edit', $record) }}"
                                            class="btn btn-sm btn-outline-secondary">Edit</a>
                                        <form method="POST"
                                            action="{{ route('admin.social-media-records.destroy', $record) }}"
                                            onsubmit="return confirm('Delete this social media record?');">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                                        </form>
                                        @if ($record->status === \App\Models\SocialMediaRecord::STATUS_PENDING)
                                            <form method="POST"
                                                action="{{ route('admin.social-media-records.moderate', $record) }}"
                                                onsubmit="return confirm('Approve this social media record for Client viewing?');">
                                                @csrf
                                                @method('PATCH')
                                                <input type="hidden" name="status" value="approved">
                                                <button type="submit" class="btn btn-sm btn-success">Approve</button>
                                            </form>
                                        @endif
                                        @if ($record->status === \App\Models\SocialMediaRecord::STATUS_PENDING)
                                            <details>
                                                <summary class="btn btn-sm btn-outline-danger">Reject</summary>
                                                <form method="POST" class="mt-2" action="{{ route('admin.social-media-records.moderate', $record) }}"
                                                    onsubmit="return confirm('Reject this social media record?');">
                                                    @csrf
                                                    @method('PATCH')
                                                    <input type="hidden" name="status" value="rejected">
                                                    <label class="visually-hidden" for="rejection-reason-{{ $record->id }}">Rejection reason</label>
                                                    <textarea id="rejection-reason-{{ $record->id }}" name="rejection_reason" rows="2"
                                                        minlength="3" maxlength="500" required class="form-control form-control-sm mb-2"
                                                        placeholder="Rejection reason"></textarea>
                                                    <button type="submit" class="btn btn-sm btn-outline-danger">Confirm rejection</button>
                                                </form>
                                            </details>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
        <div class="mt-4">{{ $records->links() }}</div>


    @endif
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const toggle = document.getElementById('select-pending-records');
            const form = document.getElementById('bulk-moderation-form');
            const reason = document.getElementById('bulk-rejection-reason');

            toggle?.addEventListener('change', () => {
                document.querySelectorAll('.pending-record-checkbox').forEach((checkbox) => {
                    checkbox.checked = toggle.checked;
                });
            });

            form?.addEventListener('submit', (event) => {
                const action = event.submitter?.value;
                const selected = document.querySelectorAll('.pending-record-checkbox:checked').length;

                if (selected === 0) {
                    event.preventDefault();
                    window.alert('Select at least one pending record first.');
                    return;
                }

                if (action === 'rejected' && reason.value.trim().length < 3) {
                    event.preventDefault();
                    reason.focus();
                    window.alert('Enter a rejection reason with at least 3 characters.');
                    return;
                }

                if (!window.confirm(event.submitter.dataset.bulkConfirm)) {
                    event.preventDefault();
                }
            });
        });
    </script>
@endpush
