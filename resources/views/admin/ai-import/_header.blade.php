@push('styles')<link rel="stylesheet" href="{{ asset('assets/catalog-ai-import.css') }}">@endpush
@push('scripts')<script src="{{ asset('assets/catalog-ai-import.js') }}" defer></script>@endpush
<header class="d-flex flex-wrap justify-content-between gap-3 mb-4">
    <div><p class="text-market fw-bold small mb-1">CATALOG · AI IMPORT</p><h1 class="h2">{{ $heading }}</h1><p class="text-secondary">Find real sources. Review the evidence. Import only what you choose.</p></div>
    <nav class="d-flex flex-wrap gap-2 align-items-start" aria-label="Catalog import navigation"><a class="btn btn-outline-secondary" href="{{ route('admin.night-markets.index') }}">Night Markets</a><a class="btn btn-outline-secondary" href="{{ route('admin.ai-import.index') }}">New search</a><a class="btn btn-outline-secondary" href="{{ route('admin.ai-import.history') }}">Drafts / Import History</a></nav>
</header>
<ol class="ai-import-steps" aria-label="Import progress">@foreach(['Search', 'Sources', 'Review', 'Import'] as $i => $step)<li @if($i === $stage) aria-current="step" @endif><span>{{ $i + 1 }}</span>{{ $step }}</li>@endforeach</ol>
@if($errors->any())<div class="alert alert-danger" role="alert"><strong>Please review:</strong><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
@if(session('status'))<div class="alert alert-success" role="status">{{ session('status') }}</div>@endif
