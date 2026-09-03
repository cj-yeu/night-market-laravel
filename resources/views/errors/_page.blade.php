<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} | {{ config('app.name') }}</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        :root { --market-orange: #d85b1f; --market-orange-dark: #a84013; --market-cream: #fff7eb; }
        body { min-height: 100vh; background: linear-gradient(145deg, var(--market-cream), #fffdf9); color: #402a20; }
        :where(a, button):focus-visible { outline: 3px solid rgba(216, 91, 31, .55); outline-offset: 3px; }
        .error-card { border: 0; border-top: 5px solid var(--market-orange); border-radius: 1rem; box-shadow: 0 .75rem 2rem rgba(94,55,30,.12); }
        .error-code { color: var(--market-orange-dark); font-size: clamp(2.5rem, 12vw, 5.5rem); font-weight: 800; line-height: 1; }
        .btn-market { --bs-btn-color: #fff; --bs-btn-bg: var(--market-orange); --bs-btn-border-color: var(--market-orange); --bs-btn-hover-color: #fff; --bs-btn-hover-bg: var(--market-orange-dark); --bs-btn-hover-border-color: var(--market-orange-dark); }
    </style>
</head>
<body>
    <main class="container py-4 py-md-5" id="main-content">
        <div class="row justify-content-center"><div class="col-12 col-md-9 col-lg-7"><section class="card error-card"><div class="card-body p-4 p-md-5 text-center">
            <p class="error-code mb-3">{{ $code }}</p>
            <h1 class="h2 fw-bold mb-3">{{ $heading }}</h1>
            <p class="text-secondary mb-4">{{ $message }}</p>
            <div class="d-flex flex-column flex-sm-row justify-content-center gap-2">
                @foreach ($actions as $action)
                    @if (($action['type'] ?? 'link') === 'back')
                        <button type="button" class="btn btn-outline-secondary" onclick="window.history.back()">{{ $action['label'] }}</button>
                    @else
                        <a class="btn {{ $action['primary'] ?? false ? 'btn-market' : 'btn-outline-secondary' }}" href="{{ $action['url'] }}">{{ $action['label'] }}</a>
                    @endif
                @endforeach
            </div>
        </div></section></div></div>
    </main>
</body>
</html>
