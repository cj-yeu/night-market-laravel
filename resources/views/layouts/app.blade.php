@php($isAdmin = auth()->check() && auth()->user()->role === \App\Models\User::ROLE_ADMIN)
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
        <link
            href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
            rel="stylesheet"
        >

        <style>
            :root {
                --market-orange: #d85b1f;
                --market-orange-dark: #a84013;
                --market-cream: #fff7eb;
                --admin-primary: #f97316;
                --admin-primary-dark: #c2410c;
                --admin-gold: #fbbf24;
                --admin-charcoal: #1f2937;
                --admin-body-text: #475569;
                --admin-cream: #fff7ed;
                --admin-surface: #ffffff;
                --admin-border: #e5e7eb;
                --admin-success: #16a34a;
                --admin-warning: #d97706;
                --admin-danger: #dc2626;
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

            .navbar-market .navbar-brand,
            .navbar-market .nav-link.active {
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

            .night-market-image-frame {
                position: relative;
                width: 100%;
                overflow: hidden;
                background: #ede9fe;
                aspect-ratio: 16 / 9;
            }

            .night-market-image {
                display: block;
                width: 100%;
                height: 100%;
                object-fit: cover;
            }

            .night-market-thumbnail {
                width: 7rem;
                min-width: 7rem;
                border-radius: 0.5rem;
            }

            .catalog-image-frame {
                position: relative;
                width: 100%;
                overflow: hidden;
                background: #fff3df;
            }

            .stall-image-frame {
                aspect-ratio: 16 / 9;
            }

            .food-image-frame {
                aspect-ratio: 4 / 3;
            }

            .catalog-image {
                display: block;
                width: 100%;
                height: 100%;
                object-fit: cover;
            }

            .catalog-thumbnail {
                width: 7rem;
                min-width: 7rem;
                border-radius: 0.5rem;
            }

            .user-avatar {
                display: inline-flex;
                flex: 0 0 auto;
                align-items: center;
                justify-content: center;
                overflow: hidden;
                color: #fff;
                background-color: var(--market-orange);
                border: 2px solid #f3b46f;
                border-radius: 50%;
                font-weight: 700;
                line-height: 1;
                object-fit: cover;
                text-transform: uppercase;
            }

            .user-avatar-sm {
                width: 2rem;
                height: 2rem;
                font-size: 0.72rem;
            }

            .user-avatar-md {
                width: 3rem;
                height: 3rem;
                font-size: 1rem;
            }

            .user-avatar-lg {
                width: 6rem;
                height: 6rem;
                font-size: 2rem;
            }

            .admin-layout {
                background: var(--admin-cream);
                color: var(--admin-body-text);
                font-family: Inter, Arial, sans-serif;
            }

            .admin-layout h1,
            .admin-layout h2,
            .admin-layout h3,
            .admin-layout h4,
            .admin-layout h5,
            .admin-layout h6 {
                color: var(--admin-charcoal);
            }

            .admin-layout .text-market {
                color: var(--admin-primary-dark) !important;
            }

            .admin-layout .market-card,
            .admin-surface-card {
                background-color: var(--admin-surface);
                border: 1px solid var(--admin-border);
                border-radius: 12px;
                box-shadow: 0 0.25rem 1rem rgba(31, 41, 55, 0.06);
            }

            .admin-layout .btn,
            .admin-layout .form-control,
            .admin-layout .form-select {
                border-radius: 8px;
            }

            .admin-layout .form-control,
            .admin-layout .form-select {
                border-color: var(--admin-border);
            }

            .admin-layout .btn-market {
                --bs-btn-bg: var(--admin-primary);
                --bs-btn-border-color: var(--admin-primary);
                --bs-btn-hover-bg: var(--admin-primary-dark);
                --bs-btn-hover-border-color: var(--admin-primary-dark);
                --bs-btn-active-bg: var(--admin-primary-dark);
                --bs-btn-active-border-color: var(--admin-primary-dark);
                --bs-btn-focus-shadow-rgb: 249, 115, 22;
            }

            .btn-admin-secondary {
                --bs-btn-color: var(--admin-primary-dark);
                --bs-btn-bg: var(--admin-surface);
                --bs-btn-border-color: var(--admin-primary);
                --bs-btn-hover-color: #fff;
                --bs-btn-hover-bg: var(--admin-primary);
                --bs-btn-hover-border-color: var(--admin-primary);
                --bs-btn-active-color: #fff;
                --bs-btn-active-bg: var(--admin-primary-dark);
                --bs-btn-active-border-color: var(--admin-primary-dark);
                --bs-btn-focus-shadow-rgb: 249, 115, 22;
            }

            .admin-content-shell {
                width: 100%;
                max-width: 90rem;
                margin-inline: auto;
            }

            .admin-surface-card {
                transition: border-color 0.15s ease, box-shadow 0.15s ease, transform 0.15s ease;
            }

            .admin-surface-card:hover,
            .admin-surface-card:focus {
                border-color: rgba(249, 115, 22, 0.45);
                box-shadow: 0 0.5rem 1.25rem rgba(31, 41, 55, 0.09);
                transform: translateY(-2px);
            }

            .admin-card-icon {
                color: var(--admin-primary-dark);
                background-color: rgba(251, 191, 36, 0.18);
            }

            .admin-sidebar {
                --bs-offcanvas-width: 260px;
                --bs-offcanvas-bg: var(--admin-charcoal);
                --bs-offcanvas-color: #f8fafc;
                --bs-nav-link-color: #f8fafc;
                --bs-nav-link-hover-color: #fff;
                --bs-nav-pills-link-active-bg: var(--admin-primary);
                width: 260px !important;
                color: #f8fafc;
                background-color: var(--admin-charcoal);
                border-right: 1px solid rgba(255, 255, 255, 0.08);
                box-shadow: 0.5rem 0 1.5rem rgba(31, 41, 55, 0.12);
            }

            .admin-sidebar .offcanvas-header,
            .admin-sidebar .offcanvas-body {
                color: #f8fafc;
                background-color: var(--admin-charcoal);
            }

            .admin-sidebar-brand {
                color: #fff;
                font-weight: 700;
                line-height: 1.25;
            }

            .admin-sidebar-brand:hover,
            .admin-sidebar-brand:focus {
                color: #fff;
            }

            .admin-brand-mark {
                display: grid;
                width: 2.5rem;
                height: 2.5rem;
                flex: 0 0 2.5rem;
                place-items: center;
                color: var(--admin-charcoal);
                background: var(--admin-gold);
                border-radius: 10px;
            }

            .admin-nav-heading {
                margin: 1.25rem 0.75rem 0.5rem;
                color: #cbd5e1;
                font-size: 0.68rem;
                font-weight: 700;
                letter-spacing: 0.1em;
            }

            .admin-sidebar .nav-pills .admin-sidebar-link {
                color: #f8fafc;
                border-radius: 8px;
                padding: 0.625rem 0.75rem;
                font-size: 0.94rem;
                font-weight: 500;
                text-align: left;
                transition: color 0.15s ease, background-color 0.15s ease;
            }

            .admin-sidebar .nav-pills .admin-sidebar-link:hover,
            .admin-sidebar .nav-pills .admin-sidebar-link:focus {
                color: #fff;
                background-color: rgba(255, 255, 255, 0.10);
            }

            .admin-sidebar .nav-pills .admin-sidebar-link.active {
                color: #fff;
                background-color: var(--admin-primary);
                box-shadow: none;
            }

            .admin-sidebar-link .bi {
                width: 1.25rem;
                margin-right: 0.55rem;
                font-size: 1rem;
                text-align: center;
            }

            .admin-sidebar-link .admin-menu-chevron {
                width: auto;
                margin-right: 0;
            }

            .admin-sidebar-submenu {
                margin: 0.35rem 0 0.25rem 1.35rem;
                padding-left: 0.75rem;
                border-left: 1px solid rgba(255, 255, 255, 0.18);
            }

            .admin-sidebar .admin-sidebar-submenu .nav-link {
                color: #cbd5e1;
                padding: 0.4rem 0.625rem;
                border-radius: 6px;
                font-size: 0.84rem;
            }

            .admin-sidebar .admin-sidebar-submenu .nav-link:hover,
            .admin-sidebar .admin-sidebar-submenu .nav-link:focus,
            .admin-sidebar .admin-sidebar-submenu .nav-link.active {
                color: #fff;
                background-color: rgba(255, 255, 255, 0.10);
            }

            .admin-menu-chevron {
                font-size: 0.85rem;
                transition: transform 0.2s ease;
            }

            [aria-expanded="true"] .admin-menu-chevron {
                transform: rotate(180deg);
            }

            .admin-account {
                background-color: rgba(255, 255, 255, 0.06);
                border: 1px solid rgba(255, 255, 255, 0.08);
                border-radius: 12px;
            }

            .admin-account .min-w-0 {
                min-width: 0;
            }

            .admin-avatar {
                display: grid;
                width: 2.25rem;
                height: 2.25rem;
                flex: 0 0 2.25rem;
                place-items: center;
                color: #fff;
                background-color: var(--admin-primary);
                border-radius: 50%;
                font-size: 0.78rem;
                font-weight: 700;
            }

            .admin-account-link {
                color: #f8fafc;
                border-radius: 8px;
                font-size: 0.82rem;
                text-decoration: none;
            }

            .admin-supporting-text {
                color: #cbd5e1;
            }

            .admin-account-link:hover,
            .admin-account-link:focus,
            .admin-account-link.active {
                color: #fff;
                background-color: rgba(255, 255, 255, 0.10);
            }

            .admin-logout-link {
                border: 0;
                background: transparent;
                color: #f8fafc;
            }

            .admin-logout-link:hover,
            .admin-logout-link:focus {
                color: #fff;
                background-color: rgba(220, 38, 38, 0.72);
            }

            .admin-mobile-header {
                position: sticky;
                top: 0;
                z-index: 1020;
                background-color: var(--admin-surface);
                border-bottom: 1px solid var(--admin-border);
            }

            .admin-main-content {
                min-width: 0;
                min-height: 100vh;
            }

            @media (min-width: 992px) {
                .admin-sidebar.offcanvas-lg {
                    position: fixed;
                    inset: 0 auto 0 0;
                    z-index: 1030;
                    height: 100vh;
                    background-color: var(--admin-charcoal) !important;
                    visibility: visible !important;
                    transform: none !important;
                }

                .admin-sidebar .offcanvas-body {
                    overflow-y: auto;
                }

                .admin-main-content {
                    margin-left: 260px;
                }
            }
        </style>

        @stack('styles')
    </head>
    <body class="{{ $isAdmin ? 'admin-layout' : '' }}">
        @if ($isAdmin)
            @include('layouts.partials.admin-sidebar')

            <div class="admin-main-content">
                <header class="admin-mobile-header d-lg-none px-3 py-2">
                    <div class="d-flex align-items-center justify-content-between gap-3">
                        <button class="btn btn-market" type="button" data-bs-toggle="offcanvas"
                            data-bs-target="#adminSidebar" aria-controls="adminSidebar" aria-label="Open admin navigation">
                            <i class="bi bi-list me-1" aria-hidden="true"></i>Menu
                        </button>
                        <span class="fw-bold text-market text-end">Night Market Admin Portal</span>
                    </div>
                </header>
        @else
            <nav class="navbar navbar-expand-lg navbar-light navbar-market">
            <div class="container">
                <a class="navbar-brand fw-bold" href="{{ url('/') }}">Night Market Selangor</a>

                <div class="d-flex flex-wrap justify-content-end align-items-center gap-2">
                    @guest
                        <a class="nav-link fw-semibold me-2" href="{{ route('home') }}">Home</a>
                        <a class="nav-link fw-semibold me-2" href="{{ route('night-markets.index') }}">
                            Discover Markets
                        </a>
                        <a class="nav-link fw-semibold me-2" href="{{ route('stalls.index') }}">Explore Stalls</a>
                        <a class="nav-link fw-semibold me-2" href="{{ route('foods.index', ['is_must_try' => '1']) }}">Must-Try Foods</a>
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
                        @if (auth()->user()->role !== \App\Models\User::ROLE_ADMIN)
                            <a class="nav-link active fw-semibold me-2" href="{{ route('client.home') }}">
                                Home
                            </a>
                            <a class="nav-link fw-semibold me-2" href="{{ route('night-markets.index') }}">
                                Discover Markets
                            </a>
                            <a class="nav-link fw-semibold me-2" href="{{ route('stalls.index') }}">Explore Stalls</a>
                            <a class="nav-link fw-semibold me-2" href="{{ route('foods.index', ['is_must_try' => '1']) }}">Must-Try Foods</a>
                            <a class="nav-link fw-semibold me-2" href="{{ route('client.visit-plans.index') }}">
                                My Visit Plans
                            </a>
                        @endif

                        <a class="nav-link fw-semibold d-flex align-items-center gap-2 me-2"
                            href="{{ route('profile.edit') }}">
                            <x-user-avatar :user="auth()->user()" size="sm" />
                            <span class="d-none d-sm-inline">Profile</span>
                        </a>

                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="btn btn-sm btn-outline-danger">Logout</button>
                        </form>
                    @endguest
                </div>
            </div>
            </nav>
        @endif

        <main class="{{ $isAdmin ? 'container-fluid px-3 px-md-4 px-xl-5' : 'container' }} py-4 py-lg-5">
            <div class="{{ $isAdmin ? 'admin-content-shell' : '' }}">
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
            </div>
        </main>

        @if ($isAdmin)
            </div>
        @endif

        <script
            src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
            integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz"
            crossorigin="anonymous"
        ></script>

        @stack('scripts')
    </body>
</html>
