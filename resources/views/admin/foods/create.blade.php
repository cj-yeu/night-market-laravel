@extends('layouts.app')

@section('title', 'Add Food | Night Market Selangor')

@section('content')
    <div class="row justify-content-center">
        <div class="col-12 col-lg-7">
            <div class="card market-card">
                <div class="card-body p-4 p-md-5">
                    <h1 class="h3 fw-bold text-market">Add Food</h1>
                    <p class="text-secondary mb-4">Add a food item to an active stall at an active Night Market in Selangor.</p>

                    @if ($stalls->isEmpty())
                        <div class="alert alert-warning mb-0">
                            No eligible Stalls are available. <a href="{{ route('admin.stalls.index') }}" class="alert-link">Manage Stalls</a> to add or activate one at an eligible Night Market first.
                        </div>
                    @else
                        <form method="POST" action="{{ route('admin.foods.store') }}" novalidate>
                            @csrf

                            <div class="mb-3">
                                <label for="stall_id" class="form-label">Stall</label>
                                <select class="form-select @error('stall_id') is-invalid @enderror"
                                    id="stall_id" name="stall_id" required>
                                    <option value="">Select a stall</option>
                                    @foreach ($stalls as $stall)
                                        <option value="{{ $stall->id }}"
                                            @selected((string) old('stall_id') === (string) $stall->id)>
                                            {{ $stall->name }} — {{ $stall->nightMarket->name }}
                                        </option>
                                    @endforeach
                                </select>
                                @error('stall_id')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="mb-3">
                                <label for="name" class="form-label">Food Name</label>
                                <input type="text" class="form-control @error('name') is-invalid @enderror"
                                    id="name" name="name" value="{{ old('name') }}" required>
                                @error('name')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="mb-3">
                                <label for="category" class="form-label">Category</label>
                                <input type="text" class="form-control @error('category') is-invalid @enderror"
                                    id="category" name="category" value="{{ old('category') }}">
                                @error('category')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="mb-3">
                                <label for="description" class="form-label">Description</label>
                                <textarea class="form-control @error('description') is-invalid @enderror"
                                    id="description" name="description" rows="4">{{ old('description') }}</textarea>
                                @error('description')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            @include('admin.foods._metadata-fields')

                            <div class="form-check mb-3">
                                <input type="hidden" name="is_must_try" value="0">
                                <input class="form-check-input @error('is_must_try') is-invalid @enderror"
                                    type="checkbox" id="is_must_try" name="is_must_try" value="1"
                                    @checked(old('is_must_try') == 1)>
                                <label class="form-check-label" for="is_must_try">Mark as must-try</label>
                                @error('is_must_try')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="mb-4">
                                <label for="status" class="form-label">Status</label>
                                <select class="form-select @error('status') is-invalid @enderror"
                                    id="status" name="status" required>
                                    <option value="active" @selected(old('status', 'active') === 'active')>Active</option>
                                    <option value="inactive" @selected(old('status') === 'inactive')>Inactive</option>
                                </select>
                                @error('status')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <button type="submit" class="btn btn-market">Add Food</button>
                        </form>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection
