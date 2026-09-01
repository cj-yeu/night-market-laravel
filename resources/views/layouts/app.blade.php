@php
    $currentUser = auth()->user();
    $isAdmin = $currentUser?->hasAdminAccess() ?? false;
    $isClient = $currentUser?->role === \App\Models\User::ROLE_CLIENT;
    $logoUrl = $isAdmin ? route('admin.dashboard') : ($isClient ? route('client.home') : route('home'));
@endphp
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

            :where(a, button, input, select, textarea):focus-visible {
                outline: 3px solid rgba(216, 91, 31, 0.52);
                outline-offset: 3px;
            }

            .skip-link {
                position: fixed;
                top: 0.5rem;
                left: 0.5rem;
                z-index: 2000;
                padding: 0.65rem 0.9rem;
                color: #fff;
                background: var(--market-orange-dark);
                border-radius: 0.5rem;
                transform: translateY(-150%);
            }

            .skip-link:focus {
                transform: translateY(0);
            }

            .navbar-market {
                position: sticky;
                top: 0;
                z-index: 1020;
                background-color: #fff;
                border-bottom: 3px solid #f3b46f;
            }

            .navbar-market .navbar-brand,
            .navbar-market .nav-link.active {
                color: var(--market-orange-dark) !important;
            }

            .navbar-market .nav-link.active {
                border-bottom: 2px solid var(--market-orange);
            }

            .navbar-market .nav-link,
            .navbar-market .navbar-toggler,
            .navbar-market .btn {
                min-height: 44px;
                display: inline-flex;
                align-items: center;
            }

            .dashboard-action { transition: transform .15s ease, box-shadow .15s ease; }
            .dashboard-action:hover, .dashboard-action:focus { transform: translateY(-2px); box-shadow: 0 .9rem 2rem rgba(94, 55, 30, .18); }

            .card :where(a:not(.btn), p, dd, .text-secondary) {
                overflow-wrap: anywhere;
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

            .password-toggle {
                z-index: 2;
                display: inline-flex;
                width: 44px;
                min-width: 44px;
                height: 44px;
                align-items: center;
                justify-content: center;
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

            .navbar-profile-avatar {
                width: 1.9rem;
                height: 1.9rem;
                object-fit: cover;
                border: 1px solid #f3b46f;
                border-radius: 50%;
            }

            .review-tag {
                display: inline-flex;
                align-items: center;
                min-height: 1.65rem;
                padding: 0.2rem 0.55rem;
                border-radius: 999px;
                font-size: 0.78rem;
                font-weight: 600;
            }

            .review-tag-warm { color: #8f3d14; background: #ffe1bd; }
            .review-tag-positive { color: #17663a; background: #d9f3e2; }
            .review-tag-info { color: #155f7a; background: #d9f1f8; }
            .review-tag-caution { color: #815d05; background: #fff1bd; }
            .review-tag-neutral { color: #52616b; background: #edf0f2; }

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

            @media (max-width: 991.98px) {
                .navbar-market .navbar-collapse {
                    margin-top: 0.5rem;
                    border-top: 1px solid #f3d7b8;
                }

                .navbar-market .navbar-nav {
                    padding: 0.5rem 0 0.75rem;
                }

                .navbar-market .nav-link {
                    min-height: 44px;
                    padding: 0.625rem 0.75rem !important;
                    border-radius: 0.5rem;
                }

                .navbar-market .nav-link.active {
                    border-bottom: 0;
                    background-color: #fff1df;
                }

                .navbar-market .navbar-nav > .btn,
                .navbar-market .navbar-nav > form,
                .navbar-market .navbar-nav > form .btn {
                    width: 100%;
                }

                .admin-sidebar .offcanvas-header {
                    min-height: 64px;
                }

                .admin-sidebar .offcanvas-header .btn-close,
                .admin-sidebar .admin-sidebar-link,
                .admin-account-link {
                    min-height: 44px;
                }

                .admin-sidebar .offcanvas-body {
                    padding-bottom: calc(1rem + env(safe-area-inset-bottom));
                }

                .table-responsive {
                    position: relative;
                    -webkit-overflow-scrolling: touch;
                    scrollbar-color: var(--market-orange) #f8e5d0;
                    scrollbar-width: thin;
                }

                .table-responsive::after {
                    content: 'Swipe table horizontally to see all columns';
                    display: block;
                    width: max-content;
                    padding: 0.5rem 0;
                    color: #6c757d;
                    font-size: 0.75rem;
                }
            }

            @media (max-width: 575.98px) {
                body {
                    font-size: 0.975rem;
                }

                .navbar-market {
                    border-bottom-width: 2px;
                }

                .navbar-market .container,
                main.container {
                    padding-right: 0.875rem;
                    padding-left: 0.875rem;
                }

                .navbar-market .navbar-brand {
                    max-width: calc(100% - 4.25rem);
                    overflow: hidden;
                    font-size: 1rem;
                    text-overflow: ellipsis;
                    white-space: nowrap;
                }

                .navbar-market .navbar-toggler {
                    width: 44px;
                    height: 44px;
                    padding: 0;
                    justify-content: center;
                }

                .navbar-market .navbar-nav {
                    padding: 0.625rem 0 0.875rem;
                    gap: 0.25rem;
                }

                .navbar-market .nav-link {
                    min-height: 44px;
                    padding: 0.625rem 0.75rem !important;
                    border-radius: 0.5rem;
                }

                .navbar-market .nav-link.active {
                    border-bottom: 0;
                    background-color: #fff1df;
                }

                .navbar-market .navbar-nav > .btn,
                .navbar-market .navbar-nav > form,
                .navbar-market .navbar-nav > form .btn {
                    width: 100%;
                }

                main.container {
                    padding-top: 1.25rem !important;
                    padding-bottom: 2rem !important;
                }

                .market-card {
                    border-top-width: 4px;
                    border-radius: 0.875rem;
                    box-shadow: 0 0.5rem 1.25rem rgba(94, 55, 30, 0.10);
                }

                .market-card .card-body {
                    padding: 1.125rem !important;
                }

                .card-body.p-5,
                .card-body.p-md-5,
                .card-body.p-lg-5 {
                    padding: 1.25rem !important;
                }

                .display-6 {
                    font-size: clamp(1.7rem, 8vw, 2.1rem);
                    line-height: 1.16;
                }

                .lead {
                    font-size: 1rem;
                    line-height: 1.55;
                }

                .btn,
                .form-control,
                .form-select {
                    min-height: 44px;
                }

                textarea.form-control {
                    min-height: 8rem;
                }

                .d-flex.flex-wrap.gap-2 > .btn,
                .d-flex.flex-wrap.gap-2 > a.btn {
                    min-height: 44px;
                }

                form .row > .col-12.d-flex > .btn,
                form .row > .col-12.d-flex > a.btn {
                    flex: 1 1 100%;
                }

                .form-check {
                    min-height: 44px;
                    display: flex;
                    align-items: center;
                }

                .form-check-input {
                    width: 1.2rem;
                    height: 1.2rem;
                    margin-top: 0;
                }

                .form-check-label {
                    padding: 0.5rem 0;
                }

                .vstack.gap-4,
                .vstack.gap-3 {
                    gap: 1rem !important;
                }

                .row.g-4 > [class*='col-'],
                .row.g-3 > [class*='col-'] {
                    min-width: 0;
                }

                .table-responsive {
                    margin-right: -1.125rem;
                    margin-left: -1.125rem;
                    padding: 0 1.125rem 0.25rem;
                    -webkit-overflow-scrolling: touch;
                }

                .table-responsive table {
                    min-width: 42rem;
                }

                .pagination {
                    flex-wrap: wrap;
                    gap: 0.25rem;
                }

                .pagination .page-link {
                    min-width: 40px;
                    min-height: 40px;
                    display: grid;
                    place-items: center;
                }

                .admin-mobile-header {
                    min-height: 60px;
                }

                .admin-mobile-header .btn {
                    min-height: 44px;
                }
            }

            @media (max-width: 374.98px) {
                .navbar-market .container,
                main.container {
                    padding-right: 0.75rem;
                    padding-left: 0.75rem;
                }

                .market-card .card-body,
                .card-body.p-5,
                .card-body.p-md-5,
                .card-body.p-lg-5 {
                    padding: 1rem !important;
                }

                .display-6 {
                    font-size: 1.65rem;
                }

                .d-flex.flex-wrap.gap-2 > .btn,
                .d-flex.flex-wrap.gap-2 > a.btn {
                    width: 100%;
                }
            }

            @media (prefers-reduced-motion: reduce) {
                *,
                *::before,
                *::after {
                    scroll-behavior: auto !important;
                    transition-duration: 0.01ms !important;
                    animation-duration: 0.01ms !important;
                    animation-iteration-count: 1 !important;
                }
            }
        </style>

        @stack('styles')
    </head>
    <body class="{{ $isAdmin ? 'admin-layout' : '' }}">
        <a class="skip-link" href="#main-content">Skip to main content</a>
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
                <a class="navbar-brand fw-bold" href="{{ $logoUrl }}">Night Market Selangor</a>
                <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#primaryNavigation" aria-controls="primaryNavigation" aria-expanded="false" aria-label="Toggle navigation"><span class="navbar-toggler-icon"></span></button>
                <div class="collapse navbar-collapse" id="primaryNavigation">
                    <div class="navbar-nav ms-auto align-items-lg-center gap-lg-1 pt-2 pt-lg-0">
                        @if (! $currentUser)
                            @foreach ([['Home', 'home', 'home'], ['Discover Markets', 'night-markets.index', 'night-markets.*'], ['Explore Stalls', 'stalls.index', 'stalls.*'], ['Must-Try Foods', 'foods.index', 'foods.*'], ['Social Media', 'social-media-highlights.index', 'social-media-highlights.*']] as [$label, $routeName, $routePattern])
                                <a class="nav-link fw-semibold px-lg-2 {{ request()->routeIs($routePattern) ? 'active' : '' }}" href="{{ route($routeName, $routeName === 'foods.index' ? ['is_must_try' => '1'] : []) }}" @if(request()->routeIs($routePattern)) aria-current="page" @endif>{{ $label }}</a>
                            @endforeach
                            <a class="btn btn-sm {{ request()->routeIs('login') ? 'btn-market' : 'btn-outline-secondary' }} ms-lg-2" href="{{ route('login') }}">Login</a>
                            <a class="btn btn-sm btn-market ms-lg-1" href="{{ route('register') }}">Register</a>
                        @elseif ($isClient)
                            @foreach ([['Home', 'client.home'], ['Discover Markets', 'night-markets.*'], ['Explore Stalls', 'stalls.*'], ['Must-Try Foods', 'foods.*'], ['Social Media', 'social-media-highlights.*'], ['My Visit Plans', 'client.visit-plans.*']] as [$label, $routePattern])
                                <a class="nav-link fw-semibold px-lg-2 {{ request()->routeIs($routePattern) ? 'active' : '' }}" href="{{ route(match($label) {'Home' => 'client.home', 'Discover Markets' => 'night-markets.index', 'Explore Stalls' => 'stalls.index', 'Must-Try Foods' => 'foods.index', 'Social Media' => 'social-media-highlights.index', default => 'client.visit-plans.index'}, $label === 'Must-Try Foods' ? ['is_must_try' => '1'] : []) }}" @if(request()->routeIs($routePattern)) aria-current="page" @endif>{{ $label }}</a>
                            @endforeach
                            <a class="nav-link fw-semibold px-lg-2 d-inline-flex align-items-center gap-1 {{ request()->routeIs('profile.*') ? 'active' : '' }}" href="{{ route('profile.edit') }}" @if(request()->routeIs('profile.*')) aria-current="page" @endif>
                                @if ($currentUser->avatarUrl())
                                    <img src="{{ $currentUser->avatarUrl() }}" class="navbar-profile-avatar" alt="">
                                @else
                                    <i class="bi bi-person-circle fs-5" aria-hidden="true"></i>
                                @endif
                                <span>Profile</span>
                            </a>
                            <form method="POST" action="{{ route('logout') }}" class="ms-lg-1">@csrf<button type="submit" class="btn btn-sm btn-outline-danger w-100">Logout</button></form>
                        @endif
                    </div>
                </div>
            </div>
            </nav>
        @endif

        <main id="main-content" tabindex="-1" class="{{ $isAdmin ? 'container-fluid px-3 px-md-4 px-xl-5' : 'container' }} py-4 py-lg-5">
            <div class="{{ $isAdmin ? 'admin-content-shell' : '' }}">
            @if (session('status'))
                <div class="alert alert-success alert-dismissible fade show" role="status" aria-live="polite"
                    @if (session('status_auto_dismiss')) data-auto-dismiss="true" @endif>
                    {{ session('status') }}
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            @endif

            @if (session('error'))
                <div class="alert alert-danger" role="alert" aria-live="assertive">
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

        <script>
            document.querySelectorAll('.is-invalid').forEach((field, index) => {
                const feedback = field.parentElement.querySelector('.invalid-feedback');
                if (!feedback) return;

                const feedbackId = feedback.id || `validation-error-${index}`;
                feedback.id = feedbackId;
                field.setAttribute('aria-invalid', 'true');
                field.setAttribute('aria-describedby', feedbackId);
            });

            document.querySelectorAll('#primaryNavigation a[href]').forEach((link) => {
                link.addEventListener('click', () => {
                    const navigation = document.querySelector('#primaryNavigation');
                    if (window.innerWidth < 992 && navigation.classList.contains('show')) {
                        bootstrap.Collapse.getOrCreateInstance(navigation).hide();
                    }
                });
            });

            document.querySelectorAll('input[type="password"][data-password-toggle]').forEach((input) => {
                const wrapper = document.createElement('div');
                wrapper.className = 'position-relative';
                input.parentNode.insertBefore(wrapper, input);
                wrapper.appendChild(input);

                const toggle = document.createElement('button');
                toggle.type = 'button';
                toggle.className = 'btn btn-link text-secondary border-0 position-absolute top-50 end-0 translate-middle-y me-1 password-toggle';
                toggle.setAttribute('aria-label', 'Show password');
                toggle.setAttribute('aria-pressed', 'false');
                toggle.innerHTML = '<i class="bi bi-eye" aria-hidden="true"></i>';

                input.style.paddingRight = '3.25rem';
                wrapper.appendChild(toggle);

                toggle.addEventListener('click', () => {
                    const isHidden = input.type === 'password';
                    input.type = isHidden ? 'text' : 'password';
                    toggle.setAttribute('aria-label', isHidden ? 'Hide password' : 'Show password');
                    toggle.setAttribute('aria-pressed', String(isHidden));
                    toggle.innerHTML = isHidden
                        ? '<i class="bi bi-eye-slash" aria-hidden="true"></i>'
                        : '<i class="bi bi-eye" aria-hidden="true"></i>';
                });
            });

            document.querySelectorAll('[data-auto-dismiss="true"]').forEach((alert) => {
                window.setTimeout(() => bootstrap.Alert.getOrCreateInstance(alert).close(), 4000);
            });
        </script>

        @stack('scripts')
    </body>
</html>
