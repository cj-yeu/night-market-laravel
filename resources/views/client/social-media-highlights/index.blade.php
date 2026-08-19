@extends('layouts.app')

@section('title', 'Social Media Highlights | Night Market Selangor')

@section('content')
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-end gap-3 mb-4">
        <div>
            <h1 class="display-6 fw-bold text-market mb-1">Social Media Highlights</h1>
            <p class="text-secondary mb-0">
                Administrator-approved public posts about Selangor night markets and foods.
            </p>
        </div>
    </div>

    <div class="card market-card mb-4">
        <div class="card-body p-4">
            <form method="GET" action="{{ route('social-media-highlights.index') }}">
                <div class="row g-3 align-items-end">
                    <div class="col-12 col-lg-8">
                        <label for="search" class="form-label">Keyword Search</label>
                        <input type="search" id="search" name="search" maxlength="255"
                            value="{{ $filters['search'] ?? '' }}"
                            class="form-control @error('search') is-invalid @enderror"
                            placeholder="Search text, hashtag, night market, or food">
                        @error('search')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-12 col-lg-4 d-flex flex-wrap gap-2">
                        <button type="submit" class="btn btn-market">Search Highlights</button>
                        @if ($filters['search'] ?? null)
                            <a href="{{ route('social-media-highlights.index') }}"
                                class="btn btn-outline-secondary">Reset Search</a>
                        @endif
                    </div>
                </div>
            </form>
        </div>
    </div>

    @if ($records->isNotEmpty())
        <section class="mb-5" aria-labelledby="social-media-insights-heading">
            <h2 id="social-media-insights-heading" class="h3 fw-bold text-market mb-3">Social Media Insights</h2>
            <div class="row g-3 mb-3">
                <div class="col-12 col-md-6 col-xl-3">
                    <div class="card h-100 border-0 shadow-sm">
                        <div class="card-body">
                            <h3 class="h6 text-secondary">Records by Platform</h3>
                            @foreach ($insights['recordsByPlatform'] as $platform => $count)
                                <div class="d-flex justify-content-between">
                                    <span>{{ $platform }}</span><strong>{{ $count }}</strong>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
                <div class="col-12 col-md-6 col-xl-3">
                    <div class="card h-100 border-0 shadow-sm">
                        <div class="card-body">
                            <h3 class="h6 text-secondary">Engagement by Platform</h3>
                            @foreach ($insights['engagementByPlatform'] as $platform => $engagement)
                                <div class="d-flex justify-content-between gap-2">
                                    <span>{{ $platform }}</span><strong>{{ number_format($engagement) }}</strong>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
                <div class="col-12 col-md-6 col-xl-3">
                    <div class="card h-100 border-0 shadow-sm">
                        <div class="card-body">
                            <h3 class="h6 text-secondary">Most-Mentioned Market</h3>
                            @if ($insights['mostMentionedMarket'])
                                <div class="fw-bold text-market">{{ $insights['mostMentionedMarket']['name'] }}</div>
                                <div class="small text-secondary">
                                    {{ $insights['mostMentionedMarket']['count'] }} approved records
                                </div>
                            @else
                                <span class="text-secondary">No confirmed market mentions.</span>
                            @endif
                        </div>
                    </div>
                </div>
                <div class="col-12 col-md-6 col-xl-3">
                    <div class="card h-100 border-0 shadow-sm">
                        <div class="card-body">
                            <h3 class="h6 text-secondary">Most-Mentioned Food</h3>
                            @if ($insights['mostMentionedFood'])
                                <div class="fw-bold text-market">{{ $insights['mostMentionedFood']['name'] }}</div>
                                <div class="small text-secondary">
                                    {{ $insights['mostMentionedFood']['count'] }} approved records
                                </div>
                            @else
                                <span class="text-secondary">No confirmed food mentions.</span>
                            @endif
                        </div>
                    </div>
                </div>
            </div>

            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <h3 class="h5 text-market">Top Engagement Posts</h3>
                    <div class="row g-2">
                        @foreach ($insights['topEngagementPosts'] as $topPost)
                            <div class="col-12 col-lg-6">
                                <div class="border rounded-3 p-3 h-100">
                                    <div class="d-flex justify-content-between gap-2">
                                        <strong>{{ $topPost->platform }}</strong>
                                        <span class="badge text-bg-warning">
                                            {{ number_format($topPost->engagement_count) }} engagement
                                        </span>
                                    </div>
                                    <div class="small text-secondary mt-1">{{ $topPost->nightMarket->name }}</div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </section>
    @endif

    @if ($records->isEmpty())
        <div class="alert alert-info text-center py-4" role="status">
            @if ($filters['search'] ?? null)
                <h2 class="h5">No approved highlights found</h2>
                <p>No approved social-media highlights match your search.</p>
                <a href="{{ route('social-media-highlights.index') }}"
                    class="btn btn-outline-secondary">Reset Search</a>
            @else
                <h2 class="h5">No approved social-media highlights yet</h2>
                <p class="mb-0">Approved public-post information will appear here after administrator review.</p>
            @endif
        </div>
    @else
        <div class="row g-4">
            @foreach ($records as $record)
                <div class="col-12 col-lg-6">
                    <article class="card market-card h-100">
                        <div class="card-body p-4 d-flex flex-column">
                            <div class="d-flex flex-wrap justify-content-between gap-2 mb-3">
                                <span class="badge text-bg-warning">{{ $record->platform }}</span>
                                <span class="text-secondary small">{{ $record->posted_date->format('d M Y') }}</span>
                            </div>

                            <h2 class="h5 fw-bold text-market">{{ $record->nightMarket->name }}</h2>
                            @if ($record->food)
                                <p class="fw-semibold mb-2">Featured food: {{ $record->food->name }}</p>
                            @endif
                            <p class="text-secondary">{{ $record->content_summary }}</p>

                            @if (! empty($record->extracted_hashtags))
                                <div class="d-flex flex-wrap gap-1 mb-3">
                                    @foreach ($record->extracted_hashtags as $hashtag)
                                        <span class="badge text-bg-light border">{{ $hashtag }}</span>
                                    @endforeach
                                </div>
                            @endif

                            <div class="small text-secondary mb-3">
                                {{ number_format($record->likes) }} likes &middot;
                                {{ number_format($record->comments) }} comments &middot;
                                {{ number_format($record->shares) }} shares
                            </div>
                            <div class="fw-semibold mb-4">
                                Total engagement: {{ number_format($record->engagement_count) }}
                            </div>

                            <div class="d-flex flex-wrap gap-2 mt-auto">
                                <a href="{{ $record->original_post_url }}" target="_blank" rel="noopener noreferrer"
                                    class="btn btn-market">Open Original Post</a>
                                @if (Route::has('night-markets.show'))
                                    <a href="{{ route('night-markets.show', $record->nightMarket) }}"
                                        class="btn btn-outline-secondary">View Market</a>
                                @endif
                                @if ($record->food && Route::has('foods.show'))
                                    <a href="{{ route('foods.show', $record->food) }}"
                                        class="btn btn-outline-secondary">View Food</a>
                                @endif
                            </div>
                        </div>
                    </article>
                </div>
            @endforeach
        </div>

        @if ($records->hasPages())
            <nav class="d-flex justify-content-between align-items-center mt-4" aria-label="Highlights pagination">
                <a class="btn btn-outline-secondary {{ $records->onFirstPage() ? 'disabled' : '' }}"
                    href="{{ $records->previousPageUrl() ?: '#' }}">Previous</a>
                <span class="text-secondary">Page {{ $records->currentPage() }} of {{ $records->lastPage() }}</span>
                <a class="btn btn-outline-secondary {{ $records->hasMorePages() ? '' : 'disabled' }}"
                    href="{{ $records->nextPageUrl() ?: '#' }}">Next</a>
            </nav>
        @endif
    @endif
@endsection
