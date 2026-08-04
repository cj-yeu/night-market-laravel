<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>@yield('title', 'Night Market Selangor')</title>

        <link
            href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
            rel="stylesheet"
            integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH"
            crossorigin="anonymous"
        >

        <style>
            :root {
                --market-orange: #d85b1f;
                --market-orange-dark: #a84013;
                --market-cream: #fff7eb;
            }

            body {
                min-height: 100vh;
                background: linear-gradient(145deg, var(--market-cream), #fffdf9);
                color: #402a20;
            }

            .navbar-market {
                background-color: #fff;
                border-bottom: 3px solid #f3b46f;
            }

            .navbar-brand,
            .nav-link.active {
                color: var(--market-orange-dark) !important;
            }

            .market-card {
                border: 0;
                border-top: 5px solid var(--market-orange);
                border-radius: 1rem;
                box-shadow: 0 0.75rem 2rem rgba(94, 55, 30, 0.12);
            }

            .btn-market {
                --bs-btn-color: #fff;
                --bs-btn-bg: var(--market-orange);
                --bs-btn-border-color: var(--market-orange);
                --bs-btn-hover-color: #fff;
                --bs-btn-hover-bg: var(--market-orange-dark);
                --bs-btn-hover-border-color: var(--market-orange-dark);
                --bs-btn-focus-shadow-rgb: 216, 91, 31;
                --bs-btn-active-color: #fff;
                --bs-btn-active-bg: var(--market-orange-dark);
                --bs-btn-active-border-color: var(--market-orange-dark);
            }

            .text-market {
                color: var(--market-orange-dark);
            }
        </style>

        @stack('styles')
    </head>
    <body>
        <nav class="navbar navbar-expand-lg navbar-light navbar-market">
            <div class="container">
                <a class="navbar-brand fw-bold" href="{{ url('/') }}">Night Market Selangor</a>

                <div class="d-flex align-items-center gap-2">
                    @guest
                        <a
                            class="btn btn-sm {{ request()->routeIs('login') ? 'btn-market' : 'btn-outline-secondary' }}"
                            href="{{ route('login') }}"
                        >
                            Login
                        </a>
                        <a
                            class="btn btn-sm {{ request()->routeIs('register') ? 'btn-market' : 'btn-outline-secondary' }}"
                            href="{{ route('register') }}"
                        >
                            Register
                        </a>
                    @else
                        @if (auth()->user()->role === \App\Models\User::ROLE_ADMIN)
                            <a class="nav-link active fw-semibold me-2" href="{{ route('admin.dashboard') }}">
                                Admin Dashboard
                            </a>
                            <a class="nav-link fw-semibold me-2" href="{{ route('admin.night-markets.create') }}">
                                Add Night Market
                            </a>
                            <a class="nav-link fw-semibold me-2" href="{{ route('admin.stalls.create') }}">Add Stall</a>
                            <a class="nav-link fw-semibold me-2" href="{{ route('admin.foods.create') }}">Add Food</a>
                        @else
                            <a class="nav-link active fw-semibold me-2" href="{{ route('client.home') }}">
                                Client Home
                            </a>
                            <a class="nav-link fw-semibold me-2" href="{{ route('client.night-markets.index') }}">
                                Discover Markets
                            </a>
                        @endif

                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="btn btn-sm btn-outline-danger">Logout</button>
                        </form>
                    @endguest
                </div>
            </div>
        </nav>

        <main class="container py-5">
            @if (session('status'))
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    {{ session('status') }}
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            @endif

            @if (session('error'))
                <div class="alert alert-danger" role="alert">
                    {{ session('error') }}
                </div>
            @endif

            @yield('content')
        </main>

        <script
            src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
            integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz"
            crossorigin="anonymous"
        ></script>

        @stack('scripts')
    </body>
</html>