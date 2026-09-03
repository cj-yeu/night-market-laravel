@extends('layouts.app')

@section('title', 'Edit Social Media Record | '.config('app.name'))

@section('content')
    <div class="row justify-content-center">
        <div class="col-12 col-xl-8">
            <div class="card market-card">
                <div class="card-body p-4 p-md-5">
                    <h1 class="h3 fw-bold text-market">Edit Social Media Record</h1>
                    <p class="text-secondary mb-4">
                        Check and edit the extracted results, then confirm the related market and optional food.
                    </p>

                    <form method="POST" action="{{ route('admin.social-media-records.update', $socialMediaRecord) }}" novalidate>
                        @csrf
                        @method('PATCH')
                        @include('admin.social-media-records._fields')

                        <div class="d-flex gap-2 mt-4">
                            <button type="submit" class="btn btn-market">Update Record</button>
                            <a href="{{ route('admin.social-media-records.index') }}"
                                class="btn btn-outline-secondary">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
