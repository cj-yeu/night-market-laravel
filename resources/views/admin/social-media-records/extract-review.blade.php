@extends('layouts.app')

@section('title', 'Review Extracted Social Media Record | Night Market Selangor')

@section('content')
    <div class="row justify-content-center">
        <div class="col-12 col-xl-9">
            <div class="card market-card">
                <div class="card-body p-4 p-md-5">
                    <h1 class="h3 fw-bold text-market">Review Social Media Record</h1>
                    <p class="text-secondary">
                        Treat all extracted values as untrusted suggestions. Edit and confirm every presentation field
                        and relationship before saving.
                    </p>

                    @if ($extractionError)
                        <div class="alert alert-warning" role="alert">
                            <strong>Automatic extraction was unavailable.</strong> {{ $extractionError }}
                            You can complete the record manually below.
                        </div>
                    @elseif ($extractionStatus === \App\Models\SocialMediaRecord::EXTRACTION_SUCCEEDED)
                        <div class="alert alert-success" role="status">
                            Public metadata was found. Review it carefully before saving.
                        </div>
                    @else
                        <div class="alert alert-info" role="status">
                            Enter the public post details manually. The record will still require moderation.
                        </div>
                    @endif

                    <form method="POST" action="{{ route('admin.social-media.extract.store') }}" novalidate>
                        @csrf
                        <input type="hidden" name="extraction_status"
                            value="{{ old('extraction_status', $extractionStatus) }}">

                        @include('admin.social-media-records._fields')

                        <div class="d-flex flex-wrap gap-2 mt-4">
                            <button type="submit" class="btn btn-market">Save as Pending</button>
                            <a href="{{ route('admin.social-media.extract.create') }}"
                                class="btn btn-outline-secondary">Try Another URL</a>
                            <a href="{{ route('admin.social-media-records.index') }}"
                                class="btn btn-outline-secondary">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
