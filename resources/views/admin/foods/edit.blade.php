@extends('layouts.app')

@section('title', 'Edit '.$food->name.' | Admin')

@section('content')
    <div class="row justify-content-center"><div class="col-12 col-lg-8"><div class="d-flex justify-content-between mb-4"><div><h1 class="h2 fw-bold mb-1">Edit Food</h1><p class="text-secondary mb-0">Status is managed separately from food details.</p></div><a href="{{ route('admin.foods.show', $food) }}" class="btn btn-outline-secondary align-self-start">Cancel</a></div><div class="card market-card"><div class="card-body p-4 p-md-5">
        <form method="POST" action="{{ route('admin.foods.update', $food) }}" novalidate>@csrf @method('PATCH')
            <div class="mb-3"><label for="stall_id" class="form-label">Stall</label><select id="stall_id" name="stall_id" class="form-select @error('stall_id') is-invalid @enderror" required>@foreach ($stalls as $stall)<option value="{{ $stall->id }}" @selected((string) old('stall_id', $food->stall_id) === (string) $stall->id)>{{ $stall->name }} — {{ $stall->nightMarket->name }}{{ $stall->status === 'inactive' ? ' (Inactive)' : '' }}</option>@endforeach</select>@error('stall_id')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
            <div class="mb-3"><label for="name" class="form-label">Food Name</label><input id="name" name="name" value="{{ old('name', $food->name) }}" class="form-control @error('name') is-invalid @enderror" required>@error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
            <div class="mb-3"><label for="category" class="form-label">Category</label><input id="category" name="category" value="{{ old('category', $food->category) }}" class="form-control @error('category') is-invalid @enderror">@error('category')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
            <div class="mb-3"><label for="description" class="form-label">Description</label><textarea id="description" name="description" rows="5" class="form-control @error('description') is-invalid @enderror">{{ old('description', $food->description) }}</textarea>@error('description')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
            @include('admin.foods._metadata-fields')
            <div class="form-check mb-4"><input type="hidden" name="is_must_try" value="0"><input class="form-check-input @error('is_must_try') is-invalid @enderror" type="checkbox" id="is_must_try" name="is_must_try" value="1" @checked((bool) old('is_must_try', $food->is_must_try))><label class="form-check-label" for="is_must_try">Mark as must-try</label>@error('is_must_try')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
            <button type="submit" class="btn btn-market">Save Changes</button>
        </form>
    </div></div></div></div>
@endsection
