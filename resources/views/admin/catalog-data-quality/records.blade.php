@extends('layouts.app')

@section('title', $definition['label'].' | Admin')

@section('content')
    <div class="mx-auto" style="max-width: 1100px;">
        <a href="{{ route('admin.catalog-data-quality.index') }}" class="btn btn-outline-secondary mb-3">Back to Data Quality</a>
        <h1 class="h2 fw-bold mb-1">{{ $definition['label'] }}</h1>
        <p class="text-secondary mb-4">Review each record individually. Missing information is not inferred or automatically published.</p>
        <div class="card market-card"><div class="card-body p-0">
            <div class="table-responsive"><table class="table align-middle mb-0">
                <thead><tr><th>Record</th><th>Parent</th><th>Status</th><th>Missing data</th><th class="text-end">Actions</th></tr></thead>
                <tbody>
                @forelse ($records as $record)
                    <tr>
                        <td class="fw-semibold">{{ $record->name }}</td>
                        <td>@if ($definition['type'] === 'market'){{ $record->city }}@elseif ($definition['type'] === 'stall'){{ $record->nightMarket->name }}@else{{ $record->stall->nightMarket->name }} · {{ $record->stall->name }}@endif</td>
                        <td><span class="badge text-bg-{{ $record->status === 'active' ? 'success' : 'secondary' }}">{{ ucfirst($record->status) }}</span></td>
                        <td class="small text-secondary">
                            @if ($definition['type'] === 'market'){{ collect([blank($record->image_path) ? 'image' : null, blank($record->source_url) ? 'source' : null, blank($record->verified_at) ? 'verified date' : null, $record->operating_days_count === 0 ? 'schedule' : null])->filter()->implode(', ') }}
                            @elseif ($definition['type'] === 'stall'){{ collect([blank($record->category) ? 'category' : null, $record->halal_status === \App\Models\Stall::HALAL_UNKNOWN ? 'halal status' : null, blank($record->source_url) ? 'source' : null, blank($record->verified_at) ? 'verified date' : null])->filter()->implode(', ') }}
                            @else{{ collect([blank($record->image_path) ? 'image' : null, blank($record->price_min) && blank($record->price_max) && blank($record->price_display) ? 'price' : null, $record->is_must_try && blank($record->recommendation_reason) ? 'recommendation reason' : null, blank($record->source_url) ? 'source' : null, blank($record->price_checked_at) ? 'price checked date' : null, blank($record->verified_at) ? 'verified date' : null])->filter()->implode(', ') }}@endif
                        </td>
                        <td class="text-end text-nowrap">
                            @php($prefix = $definition['type'] === 'market' ? 'admin.night-markets' : ($definition['type'] === 'stall' ? 'admin.stalls' : 'admin.foods'))
                            <a class="btn btn-sm btn-outline-secondary" href="{{ route($prefix.'.show', $record) }}">View</a>
                            <a class="btn btn-sm btn-market" href="{{ route($prefix.'.edit', $record) }}?return_to={{ urlencode(request()->getRequestUri()) }}">Edit</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-center text-secondary py-5">No records need this review.</td></tr>
                @endforelse
                </tbody>
            </table></div>
        </div></div>
        <div class="mt-4">{{ $records->links() }}</div>
    </div>
@endsection
