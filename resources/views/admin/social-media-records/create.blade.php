@extends('layouts.app')

@section('title', 'Add Social Media Record | '.config('app.name'))

@section('content')
    <div class="row justify-content-center">
        <div class="col-12 col-xl-8">
            <div class="card market-card">
                <div class="card-body p-4 p-md-5">
                    <h1 class="h3 fw-bold text-market">Add Social Media Record</h1>
                    <p class="text-secondary mb-4">
                        Enter a public post manually. It will be saved as pending and must use a supported platform URL.
                    </p>

                    <form method="POST" action="{{ route('admin.social-media-records.store') }}" novalidate>
                        @csrf

                        @include('admin.social-media-records._fields', ['socialMediaRecord' => null])

                        <div class="d-flex gap-2 mt-4">
                            <button type="submit" class="btn btn-market">Add Record</button>
                            <a href="{{ route('admin.social-media-records.index') }}"
                                class="btn btn-outline-secondary">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
