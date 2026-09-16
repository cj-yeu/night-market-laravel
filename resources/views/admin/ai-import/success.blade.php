@extends('layouts.app')
@section('title', 'Import complete | NightBite')
@section('content')
<div class="ai-import">
@include('admin.ai-import._header', ['heading' => 'Your catalog import is complete', 'stage' => 3])
<div class="alert alert-success" role="status">Created {{ $result['counts']['markets'] }} Night Markets, {{ $result['counts']['stalls'] }} Stalls and {{ $result['counts']['foods'] }} Foods. Linked {{ $result['counts']['linked'] }} existing Stalls/Foods without overwriting them. New records are inactive.</div>
<p>Refreshing this summary does not analyse sources or repeat the import. Review new records before activating them.</p>
<a class="btn btn-market mb-4" href="{{ route($result['stall_id'] ? 'admin.stalls.show' : 'admin.night-markets.show', $result['stall_id'] ?: $result['market_id']) }}">View {{ $result['stall_id'] ? 'Stall' : 'Night Market' }}</a>
<h2 class="h4">Imported records</h2>
<ul class="list-group mb-4">@foreach($result['records'] as $record)<li class="list-group-item d-flex flex-wrap justify-content-between gap-2"><span>{{ $record['operation'] ?? 'Imported' }} {{ ucfirst($record['type']) }}: {{ $record['name'] }}</span><a class="btn btn-outline-market" href="{{ route(match($record['type']) { 'food'=>'admin.foods.show', 'market'=>'admin.night-markets.show', default=>'admin.stalls.show' }, $record['id']) }}">View record</a></li>@endforeach</ul>
<h2 class="h4">Skipped records ({{ count($result['skipped'] ?? []) }})</h2>
<ul>@forelse($result['skipped'] ?? [] as $record)<li>{{ $record['type'] }}: {{ $record['name'] }} — {{ $record['reason'] }}</li>@empty<li>No extracted records were skipped.</li>@endforelse</ul>
</div>
@endsection
