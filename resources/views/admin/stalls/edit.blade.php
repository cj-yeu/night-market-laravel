@extends('layouts.app')

@section('title', 'Edit '.$stall->name.' | Admin')

@section('content')
    <div class="row justify-content-center"><div class="col-12 col-lg-8"><div class="d-flex justify-content-between mb-4"><div><h1 class="h2 fw-bold mb-1">Edit Stall</h1><p class="text-secondary mb-0">Status is managed separately from stall details.</p></div><a href="{{ route('admin.stalls.show', $stall) }}" class="btn btn-outline-secondary align-self-start">Cancel</a></div><div class="card market-card"><div class="card-body p-4 p-md-5">
        <form method="POST" action="{{ route('admin.stalls.update', $stall) }}" novalidate>@csrf @method('PATCH')
            <div class="mb-3"><label for="night_market_id" class="form-label">Night Market</label><select id="night_market_id" name="night_market_id" class="form-select @error('night_market_id') is-invalid @enderror" required>@foreach ($nightMarkets as $nightMarket)<option value="{{ $nightMarket->id }}" @selected((string) old('night_market_id', $stall->night_market_id) === (string) $nightMarket->id)>{{ $nightMarket->name }} — {{ $nightMarket->city }}{{ $nightMarket->status === 'inactive' ? ' (Inactive)' : '' }}</option>@endforeach</select>@error('night_market_id')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
            <div class="mb-3"><label for="name" class="form-label">Stall Name</label><input id="name" name="name" value="{{ old('name', $stall->name) }}" class="form-control @error('name') is-invalid @enderror" required>@error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
            <div class="mb-4"><label for="description" class="form-label">Description</label><textarea id="description" name="description" rows="5" class="form-control @error('description') is-invalid @enderror">{{ old('description', $stall->description) }}</textarea>@error('description')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
            <button type="submit" class="btn btn-market">Save Changes</button>
        </form>
    </div></div></div></div>
@endsection
