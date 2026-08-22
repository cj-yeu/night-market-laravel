@extends('layouts.app')

@section('title', 'Catalog Activity Log | Admin')

@section('content')
    <div class="mx-auto" style="max-width: 1200px;">
        <header class="mb-4">
            <h1 class="h2 fw-bold mb-1">Catalog Activity Log</h1>
            <p class="text-secondary mb-0">A safe, read-only history of authenticated Admin catalog actions.</p>
        </header>

        <form method="GET" class="card market-card mb-4"><div class="card-body p-3"><div class="row g-3 align-items-end">
            <div class="col-12 col-md-4"><label for="search" class="form-label">Search summary</label><input id="search" name="search" value="{{ $filters['search'] ?? '' }}" class="form-control" maxlength="100"></div>
            <div class="col-6 col-md-3"><label for="entity_type" class="form-label">Entity type</label><select id="entity_type" name="entity_type" class="form-select"><option value="">All types</option><option value="night_market" @selected(($filters['entity_type'] ?? '') === 'night_market')>Night Market</option><option value="stall" @selected(($filters['entity_type'] ?? '') === 'stall')>Stall</option><option value="food" @selected(($filters['entity_type'] ?? '') === 'food')>Food</option></select></div>
            <div class="col-6 col-md-3"><label for="action" class="form-label">Action</label><select id="action" name="action" class="form-select"><option value="">All actions</option>@foreach (['created' => 'Created', 'updated' => 'Updated', 'activated' => 'Activated', 'deactivated' => 'Deactivated', 'image_updated' => 'Image updated', 'image_removed' => 'Image removed'] as $value => $label)<option value="{{ $value }}" @selected(($filters['action'] ?? '') === $value)>{{ $label }}</option>@endforeach</select></div>
            <div class="col-12 col-md-2 d-grid"><button class="btn btn-market">Apply filters</button></div>
        </div></div></form>

        <div class="card market-card"><div class="card-body p-0"><div class="table-responsive"><table class="table align-middle mb-0">
            <thead><tr><th>Admin</th><th>Action</th><th>Entity</th><th>Summary</th><th>Date / time</th><th>Safe details</th></tr></thead>
            <tbody>@forelse ($logs as $log)
                <tr><td>{{ $log->user?->name ?? 'Deleted user' }}</td><td>{{ ucfirst(str_replace('_', ' ', $log->action)) }}</td><td>{{ ucfirst(str_replace('_', ' ', $log->entity_type)) }} #{{ $log->entity_id }}</td><td>{{ $log->summary }}</td><td class="text-nowrap">{{ $log->created_at->format('M j, Y g:i A') }}</td><td>@if ($log->changed_fields)<details><summary>View</summary><dl class="small mb-0 mt-2">@foreach ($log->changed_fields as $field)<dt>{{ $field['label'] ?? 'Changed field' }}</dt><dd class="mb-1">@if(array_key_exists('before', $field)){{ $field['before'] ?? '—' }} → @endif{{ $field['after'] ?? '—' }}</dd>@endforeach</dl></details>@else<span class="text-secondary">—</span>@endif</td></tr>
            @empty<tr><td colspan="6" class="text-center text-secondary py-5">No catalog activity matches these filters.</td></tr>@endforelse</tbody>
        </table></div></div></div>
        <div class="mt-4">{{ $logs->links() }}</div>
    </div>
@endsection
