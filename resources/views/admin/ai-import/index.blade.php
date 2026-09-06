@extends('layouts.app')
@section('title', 'Find catalog sources | NightBite')
@section('content')
<div class="ai-import">
@include('admin.ai-import._header', ['heading' => 'Find your next catalog discovery', 'stage' => $result ? 1 : 0])
@if($searchExpired)<div class="alert alert-info" role="status">These search results expired. Search again; no analysis was automatically repeated.</div>@endif
<div class="alert alert-info" role="status">
<strong>Automatic source search</strong>: Articles — {{ $searchStatus['articles'] ? 'configured' : 'not configured' }}; Videos — {{ $searchStatus['videos'] ? 'configured' : 'not configured' }}.
<p class="mb-0">Search and Gemini analysis are separate. {{ $searchStatus['available'] ? 'Configured providers are subject to their available free quota.' : 'Automatic search needs a search provider key.' }} You can still add a public source link below, analyse its content, edit a draft and Review Import. Search previews are not analysed content.</p>
</div>
<section class="card market-card mb-4"><div class="card-body p-4">
<h2 class="h4">Choose a Selangor Night Market</h2>
<form action="{{ route('admin.ai-import.search') }}" method="POST" data-ai-busy="Searching sources…" class="row g-3" data-import-context>@csrf
<input type="hidden" name="module" value="{{ $context['module'] }}">
<div class="col-lg-6"><label class="form-label" for="market">Existing Night Market</label><select name="market_id" id="market" class="form-select" data-market><option value="">Search for a new market</option>@foreach($nightMarkets as $market)<option value="{{ $market->id }}" data-name="{{ $market->name }}" data-city="{{ $market->city }}" @selected(old('market_id',$context['market_id']) == $market->id)>{{ $market->name }} · {{ $market->city }}</option>@endforeach</select></div>
<div class="col-lg-6"><label class="form-label" for="stall">Target Stall (optional)</label><select name="stall_id" id="stall" class="form-select" data-stall><option value="">Find Stalls & Foods</option>@foreach($stalls as $stall)<option value="{{ $stall->id }}" data-market="{{ $stall->night_market_id }}" @selected(old('stall_id',$context['stall_id']) == $stall->id)>{{ $stall->name }}</option>@endforeach</select></div>
<div class="col-lg-6"><label class="form-label" for="name">Night Market name / alias</label><input id="name" name="name" class="form-control" required maxlength="255" value="{{ old('name',$context['name']) }}" data-market-name></div>
<div class="col-lg-4"><label class="form-label" for="city">City / district</label><input id="city" name="city" class="form-control" required maxlength="100" value="{{ old('city',$context['city']) }}" data-market-city></div>
<div class="col-lg-2"><label class="form-label">State</label><p class="form-control-plaintext">Selangor only</p></div>
<div class="col-lg-6"><label for="search-kind" class="form-label">Search for</label><select id="search-kind" name="search_kind" class="form-select"><option value="all" @selected(old('search_kind',$result['search_kind'] ?? 'all') === 'all')>Articles and videos</option><option value="articles" @selected(old('search_kind',$result['search_kind'] ?? 'all') === 'articles')>Articles</option><option value="videos" @selected(old('search_kind',$result['search_kind'] ?? 'all') === 'videos')>Videos</option></select></div>
<div><button class="btn btn-market" type="submit">Search Sources</button> <a class="btn btn-outline-secondary" href="{{ route('admin.ai-import.index') }}">Reset</a><p class="small mt-2 mb-0" role="status" data-busy-status>Search retrieves sources only. Detailed analysis starts after you select a source.</p></div>
</form></div></section>
@if($result)
@foreach($result['notices'] ?? [] as $notice)<div class="alert alert-warning" role="status">{{ $notice }}</div>@endforeach
<section aria-labelledby="sources-heading"><h2 id="sources-heading" class="h3">Sources for {{ $context['name'] }}</h2><p>{{ count($result['sources']) }} retrieved sources · {{ $context['city'] }}, Selangor. Confirm each source refers to the intended location.</p>
<div class="d-flex flex-wrap gap-2 mb-3"><label>Source type<select class="form-select" data-source-filter><option value="all">All</option><option value="article">Articles</option><option value="video">Videos</option></select></label><label>Sort<select class="form-select" data-source-sort><option value="relevance">Relevance</option><option value="title">Title A–Z</option><option value="newest">Newest known date</option></select></label><button type="button" class="btn btn-outline-secondary align-self-end" data-source-reset>Reset</button></div>
<form action="{{ route('admin.ai-import.start') }}" method="POST" data-ai-busy="Preparing your draft…">@csrf<input type="hidden" name="search_id" value="{{ $searchId }}">
<div class="ai-source-grid" data-source-list>@forelse($result['sources'] as $i => $source)
<article class="card market-card" data-source-card data-type="{{ $source['type'] }}" data-title="{{ $source['title'] }}" data-date="{{ $source['published_at'] ?? '' }}" data-order="{{ $i }}"><div class="card-body d-flex flex-column gap-2">
@if($source['thumbnail'])<img src="{{ $source['thumbnail'] }}" alt="Source preview" loading="lazy" class="ai-source-image">@endif
<span class="badge text-bg-light align-self-start">{{ ucfirst($source['type']) }} · {{ $source['status'] }}</span><h3 class="h5">{{ $source['title'] }}</h3><p class="small text-secondary">{{ $source['publisher'] }} · {{ $source['published_at'] ?? 'Publication date unavailable' }}</p><p>{{ $source['description'] ?: 'Retrieved for this search. Location and content have not yet been verified.' }}</p>
<a href="{{ $source['url'] }}" target="_blank" rel="noopener noreferrer">Open Source <span class="visually-hidden">(new tab)</span></a><label class="ai-check mt-auto"><input class="form-check-input" type="checkbox" name="source_ids[]" value="{{ $i }}"> Select for Analysis</label></div></article>
@empty<div class="alert alert-info">No retrieved sources were returned. Refine the name/city or paste a known source URL.</div>@endforelse</div>
<div class="ai-selection-bar"><span data-selection-count>0 selected sources</span><button class="btn btn-market" type="submit">Continue with Selected Sources</button><span role="status" data-busy-status></span></div></form>
@if($result['search_suggestions'])<iframe title="Google Search suggestions" sandbox="allow-popups allow-popups-to-escape-sandbox" referrerpolicy="no-referrer" srcdoc="{{ $result['search_suggestions'] }}" class="w-100 border-0"></iframe>@endif
</section>@endif
<details class="card market-card mt-4" @if(! $searchStatus['available']) open @endif><summary class="p-3 fw-bold">Have a source already? Paste URL</summary><div class="card-body"><p>Public articles and YouTube videos are supported. You can add text or screenshots after creating a draft.</p><form action="{{ route('admin.ai-import.start') }}" method="POST" data-paste-source data-ai-busy="Preparing draft…">@csrf
@foreach($context as $key => $value)@if(in_array($key,['module','market_id','stall_id','name','city']))<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endif @endforeach
<label for="source-url" class="form-label">Source URL</label><input type="url" id="source-url" name="url" class="form-control mb-3" placeholder="https://…" required><button class="btn btn-market">Create Draft</button><span role="status" data-busy-status></span></form></div></details>
</div>
@endsection
