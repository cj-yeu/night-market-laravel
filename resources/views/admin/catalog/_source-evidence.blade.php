@if($record->catalogSourceLinks->isNotEmpty())
<section class="card market-card mt-4" aria-labelledby="source-evidence-heading-{{ $record->getTable() }}-{{ $record->id }}"><div class="card-body p-4">
<h2 id="source-evidence-heading-{{ $record->getTable() }}-{{ $record->id }}" class="h5">Import source evidence</h2>
<div class="vstack gap-3">@foreach($record->catalogSourceLinks as $link)<article class="border rounded p-3">
<div class="d-flex flex-wrap justify-content-between gap-2"><strong>{{ $link->socialMediaSource?->title ?: 'Catalog import source' }}</strong><a href="{{ route('admin.ai-import.show',$link->catalog_import_proposal_id) }}">Draft #{{ $link->catalog_import_proposal_id }}</a></div>
@if($link->socialMediaSource?->canonical_url)<a href="{{ $link->socialMediaSource->canonical_url }}" target="_blank" rel="noopener noreferrer">Open Source</a>@endif
<p class="small text-secondary mb-1">Published: {{ $link->source_published_at?->format('d M Y') ?? $link->socialMediaSource?->published_at?->format('d M Y') ?? 'Date unavailable' }} · {{ $link->evidence_method === 'admin_provided' ? 'Admin-provided evidence' : 'Automatically extracted evidence' }}</p>
<p class="mb-0">{{ $link->evidence_text ?: 'No evidence excerpt was retained for this linked record.' }}</p>
</article>@endforeach</div></div></section>
@endif
