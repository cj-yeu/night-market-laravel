@extends('layouts.app')

@section('title', 'Social Media Records | Night Market Selangor')

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
                                class="btn btn-outline-secondary">Clear</a>
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
        {{-- The row checkboxes live inside the table and reference this form by id,
             because the table already contains its own forms and HTML forbids nesting. --}}
        <form id="bulkModerateForm" method="POST"
            action="{{ route('admin.social-media-records.bulk-moderate') }}" class="mb-3 d-none">
            @csrf
            @method('PATCH')

            <div class="card market-card">
                <div class="card-body d-flex flex-wrap align-items-center gap-2 py-3">
                    <span class="fw-semibold" id="bulkSelectedCount">0 selected</span>
                    <div class="ms-auto d-flex flex-wrap gap-2">
                        <button type="submit" name="status"
                            value="{{ \App\Models\SocialMediaRecord::STATUS_APPROVED }}"
                            class="btn btn-sm btn-success">Approve Selected</button>
                        <button type="button" class="btn btn-sm btn-outline-danger"
                            data-bs-toggle="modal" data-bs-target="#bulkRejectModal">Reject Selected</button>
                    </div>
                </div>
            </div>
        </form>

        <div class="card market-card">
            <div class="table-responsive">
                <table class="table table-striped table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th class="text-center">
                                <input type="checkbox" id="bulkSelectAll" class="form-check-input"
                                    aria-label="Select all records on this page">
                            </th>
                            <th>Night Market</th>
                            <th>Related Food</th>
                            <th>Platform</th>
                            <th>Extraction</th>
                            <th>Status</th>
                            <th>Original Post</th>
                            <th>Title / Excerpt</th>
                            <th>Posted Date</th>
                            <th>Likes</th>
                            <th>Comments</th>
                            <th>Shares</th>
                            <th>Total Engagement</th>
                            <th>Extracted Information</th>
                            <th>Created</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($records as $record)
                            <tr>
                                <td class="text-center">
                                    <input type="checkbox" class="form-check-input bulk-select"
                                        form="bulkModerateForm" name="ids[]" value="{{ $record->id }}"
                                        aria-label="Select record {{ $record->id }}">
                                </td>
                                <td>{{ $record->nightMarket?->name ?? 'Unavailable market' }}</td>
                                <td>{{ $record->food?->name ?? 'None' }}</td>
                                <td><span class="badge text-bg-warning">{{ $record->platform }}</span></td>
                                <td>{{ ucfirst($record->extraction_status) }}</td>
                                <td style="min-width: 12rem;">
                                    <span class="badge {{ $record->status === \App\Models\SocialMediaRecord::STATUS_APPROVED ? 'text-bg-success' : ($record->status === \App\Models\SocialMediaRecord::STATUS_REJECTED ? 'text-bg-danger' : 'text-bg-secondary') }}">
                                        {{ ucfirst($record->status) }}
                                    </span>
                                    @if ($record->status === \App\Models\SocialMediaRecord::STATUS_REJECTED && $record->rejection_reason)
                                        <div class="small mt-1">{{ $record->rejection_reason }}</div>
                                        <div class="small text-secondary">
                                            &mdash; {{ $record->rejectedBy?->name ?? 'Unknown administrator' }}@if ($record->rejected_at), {{ $record->rejected_at->format('d M Y') }}@endif
                                        </div>
                                    @endif
                                </td>
                                <td>
                                    @if ($record->safe_source_url)
                                        <a href="{{ $record->safe_source_url }}" target="_blank" rel="noopener noreferrer">
                                            Open post
                                        </a>
                                    @else
                                        <span class="text-secondary">URL unavailable</span>
                                    @endif
                                </td>
                                <td style="min-width: 16rem;">
                                    @if ($record->extracted_title)<strong class="d-block">{{ $record->extracted_title }}</strong>@endif
                                    {{ \Illuminate\Support\Str::limit($record->content_summary, 220) }}
                                </td>
                                <td>{{ $record->posted_date->format('d M Y') }}</td>
                                <td>{{ number_format($record->likes) }}</td>
                                <td>{{ number_format($record->comments) }}</td>
                                <td>{{ number_format($record->shares) }}</td>
                                <td>{{ number_format($record->engagement_count) }}</td>
                                <td style="min-width: 15rem;">
                                    @if (empty($record->extracted_hashtags)
                                        && empty($record->extracted_market_mentions)
                                        && empty($record->extracted_food_mentions)
                                        && empty($record->extracted_location_mentions))
                                        <span class="text-secondary">No extracted matches</span>
                                    @else
                                        @foreach ($record->extracted_hashtags ?? [] as $hashtag)
                                            <span class="badge text-bg-light border mb-1">{{ $hashtag }}</span>
                                        @endforeach
                                        <div class="small mt-1">
                                            <strong>Markets:</strong>
                                            {{ implode(', ', $record->extracted_market_mentions ?? []) ?: 'None' }}
                                        </div>
                                        <div class="small">
                                            <strong>Locations:</strong>
                                            {{ implode(', ', $record->extracted_location_mentions ?? []) ?: 'None' }}
                                        </div>
                                        <div class="small">
                                            <strong>Foods:</strong>
                                            {{ implode(', ', $record->extracted_food_mentions ?? []) ?: 'None' }}
                                        </div>
                                    @endif
                                </td>
                                <td>{{ $record->created_at->format('d M Y') }}</td>
                                <td>
                                    <div class="d-flex gap-2">
                                        <a href="{{ route('admin.social-media-records.edit', $record) }}"
                                            class="btn btn-sm btn-outline-secondary">Edit</a>
                                        <form method="POST"
                                            action="{{ route('admin.social-media-records.destroy', $record) }}"
                                            onsubmit="return confirm('Delete this social media record?');">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                                        </form>
                                        @if ($record->status !== \App\Models\SocialMediaRecord::STATUS_APPROVED)
                                            <form method="POST"
                                                action="{{ route('admin.social-media-records.moderate', $record) }}"
                                                onsubmit="return confirm('Approve this social media record for Client viewing?');">
                                                @csrf
                                                @method('PATCH')
                                                <input type="hidden" name="status" value="approved">
                                                <button type="submit" class="btn btn-sm btn-success">Approve</button>
                                            </form>
                                        @endif
                                        @if ($record->status !== \App\Models\SocialMediaRecord::STATUS_REJECTED)
                                            <button type="button" class="btn btn-sm btn-outline-danger"
                                                data-bs-toggle="modal" data-bs-target="#rejectRecordModal"
                                                data-reject-action="{{ route('admin.social-media-records.moderate', $record) }}"
                                                data-reject-label="{{ $record->extracted_title ?: $record->original_post_url }}">
                                                Reject
                                            </button>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        @if ($records->hasPages())
            <nav class="d-flex justify-content-between align-items-center mt-4" aria-label="Social media records pagination">
                <a class="btn btn-outline-secondary {{ $records->onFirstPage() ? 'disabled' : '' }}"
                    href="{{ $records->previousPageUrl() ?: '#' }}">Previous</a>
                <span class="text-secondary">Page {{ $records->currentPage() }} of {{ $records->lastPage() }}</span>
                <a class="btn btn-outline-secondary {{ $records->hasMorePages() ? '' : 'disabled' }}"
                    href="{{ $records->nextPageUrl() ?: '#' }}">Next</a>
            </nav>
        @endif
    @endif

    <div class="modal fade" id="bulkRejectModal" tabindex="-1"
        aria-labelledby="bulkRejectModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 class="modal-title h5" id="bulkRejectModalLabel">Reject Selected Records</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body">
                    <p class="text-secondary small mb-3" id="bulkRejectTarget"></p>

                    <label for="bulk_rejection_reason" class="form-label">Reason for rejection</label>
                    <textarea id="bulk_rejection_reason" name="rejection_reason" form="bulkModerateForm"
                        rows="4" minlength="10" maxlength="500" class="form-control"
                        placeholder="Explain why these records are not suitable for publication."></textarea>
                    <div class="form-text">
                        Between 10 and 500 characters. The same reason is stored on every selected record.
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" form="bulkModerateForm" name="status"
                        value="{{ \App\Models\SocialMediaRecord::STATUS_REJECTED }}"
                        class="btn btn-outline-danger">Reject Selected</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="rejectRecordModal" tabindex="-1"
        aria-labelledby="rejectRecordModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <form method="POST" id="rejectRecordForm" class="modal-content">
                @csrf
                @method('PATCH')
                <input type="hidden" name="status"
                    value="{{ \App\Models\SocialMediaRecord::STATUS_REJECTED }}">

                <div class="modal-header">
                    <h2 class="modal-title h5" id="rejectRecordModalLabel">Reject Social Media Record</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body">
                    <p class="text-secondary small mb-3" id="rejectRecordTarget"></p>

                    <label for="rejection_reason" class="form-label">Reason for rejection</label>
                    <textarea id="rejection_reason" name="rejection_reason" rows="4"
                        minlength="10" maxlength="500" 
                        class="form-control @error('rejection_reason') is-invalid @enderror"
                        placeholder="Explain why this record is not suitable for publication."></textarea>
                    @error('rejection_reason')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    <div class="form-text">
                        Between 10 and 500 characters. The reason is stored with the record so the
                        decision can be reviewed later.
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-outline-danger">Reject Record</button>
                </div>
            </form>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const modal = document.getElementById('rejectRecordModal');

            if (!modal) {
                return;
            }

            const form = document.getElementById('rejectRecordForm');
            const target = document.getElementById('rejectRecordTarget');
            const reason = document.getElementById('rejection_reason');

            // One modal serves every row: the button that opened it carries the
            // record's moderation route and a label for the operator to confirm.
            modal.addEventListener('show.bs.modal', (event) => {
                const trigger = event.relatedTarget;

                if (!trigger) {
                    return;
                }

                form.action = trigger.dataset.rejectAction;
                target.textContent = trigger.dataset.rejectLabel ?? '';
                reason.value = '';
            });

            modal.addEventListener('shown.bs.modal', () => reason.focus());
        });

        document.addEventListener('DOMContentLoaded', () => {
            const bulkForm = document.getElementById('bulkModerateForm');

            if (!bulkForm) {
                return;
            }

            const rowBoxes = Array.from(document.querySelectorAll('.bulk-select'));
            const selectAll = document.getElementById('bulkSelectAll');
            const counter = document.getElementById('bulkSelectedCount');
            const bulkTarget = document.getElementById('bulkRejectTarget');
            const bulkReason = document.getElementById('bulk_rejection_reason');

            const refresh = () => {
                const selected = rowBoxes.filter((box) => box.checked).length;
                const label = selected === 1 ? '1 record selected' : `${selected} records selected`;

                counter.textContent = label;
                bulkTarget.textContent = label;
                bulkForm.classList.toggle('d-none', selected === 0);

                selectAll.checked = selected > 0 && selected === rowBoxes.length;
                selectAll.indeterminate = selected > 0 && selected < rowBoxes.length;
            };

            rowBoxes.forEach((box) => box.addEventListener('change', refresh));

            selectAll.addEventListener('change', () => {
                rowBoxes.forEach((box) => {
                    box.checked = selectAll.checked;
                });
                refresh();
            });

            const bulkRejectModal = document.getElementById('bulkRejectModal');

            if (bulkRejectModal) {
                bulkRejectModal.addEventListener('shown.bs.modal', () => bulkReason.focus());

                // The textarea lives in a collapsed modal but belongs to the bulk form.
                // Leaving it permanently required makes the browser silently block the
                // Approve submit: it cannot focus a hidden invalid control to complain.
                bulkRejectModal.addEventListener('show.bs.modal', () => {
                    bulkReason.required = true;
                });

                bulkRejectModal.addEventListener('hidden.bs.modal', () => {
                    bulkReason.required = false;
                });
            }

            refresh();
        });
    </script>
@endpush
