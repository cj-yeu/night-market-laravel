@extends('layouts.app')

@section('title', 'Extract Social Media Metadata | Night Market Selangor')

@section('content')
    <div class="row justify-content-center">
        <div class="col-12 col-xl-8">
            <div class="card market-card">
                <div class="card-body p-4 p-md-5">
                    <h1 class="h3 fw-bold text-market">Extract Public Post Metadata</h1>
                    <p class="text-secondary">
                        Enter a public Instagram, TikTok, Facebook, YouTube, or X/Twitter post URL. Extraction is
                        best-effort and uses public page metadata only—no login, cookies, or platform API.
                    </p>

                    @if ($errors->any())
                        <div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>
                    @endif

                    <form method="POST" action="{{ route('admin.social-media.extract.extract') }}" novalidate>
                        @csrf
                        <label for="source_url" class="form-label">Public Post URL</label>
                        <input type="url" id="source_url" name="source_url" maxlength="2048"
                            value="{{ old('source_url') }}"
                            class="form-control @error('source_url') is-invalid @enderror"
                            placeholder="https://www.instagram.com/p/..." required>
                        @error('source_url')<div class="invalid-feedback">{{ $message }}</div>@enderror

                        <div class="d-flex flex-wrap gap-2 mt-4">
                            <button type="submit" class="btn btn-market">Attempt Extraction</button>
                            <a href="{{ route('admin.social-media.extract.review') }}"
                                class="btn btn-outline-secondary">Enter Manually</a>
                            <a href="{{ route('admin.social-media-records.index') }}"
                                class="btn btn-outline-secondary">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
