@extends('layouts.app')

@section('title', 'Admin Dashboard | Night Market Selangor')

@section('content')
    <header class="mb-4">
        <h1 class="h2 fw-bold mb-2">Dashboard</h1>
        <p class="mb-1 fs-5 text-dark">Welcome back, {{ auth()->user()->name }}.</p>
        <p class="text-secondary mb-0">Manage your night market platform from one place.</p>
    </header>

    <section aria-labelledby="management-heading">
        <h2 class="h4 fw-semibold mb-3" id="management-heading">Management</h2>
        <div class="row g-3">
            @php
                $managementLinks = [
                    ['label' => 'Users', 'description' => 'Manage account access and status.', 'icon' => 'people-fill', 'route' => 'admin.users.index'],
                    ['label' => 'Night Markets', 'description' => 'Add markets and operating schedules.', 'icon' => 'shop', 'route' => 'admin.night-markets.create'],
                    ['label' => 'Stalls', 'description' => 'Add stalls to active night markets.', 'icon' => 'basket-fill', 'route' => 'admin.stalls.create'],
                    ['label' => 'Foods', 'description' => 'Organize must-try food information.', 'icon' => 'cup-hot-fill', 'route' => 'admin.foods.create'],
                    ['label' => 'Reviews', 'description' => 'View and manage published visitor feedback.', 'icon' => 'star-fill', 'route' => 'admin.reviews.index'],
                    ['label' => 'Social Media', 'description' => 'Manage extracted social media records.', 'icon' => 'megaphone-fill', 'route' => 'admin.social-media-records.index'],
                ];
            @endphp

            @foreach ($managementLinks as $link)
                <div class="col-12 col-md-6 col-xl-4">
                    <a href="{{ route($link['route']) }}"
                        class="admin-surface-card d-flex align-items-start gap-3 h-100 p-4 text-decoration-none">
                        <span class="admin-card-icon rounded-3 px-3 py-2" aria-hidden="true">
                            <i class="bi bi-{{ $link['icon'] }} fs-4"></i>
                        </span>
                        <span>
                            <span class="d-block h5 fw-semibold text-dark mb-1">{{ $link['label'] }}</span>
                            <span class="d-block small text-secondary">{{ $link['description'] }}</span>
                        </span>
                    </a>
                </div>
            @endforeach
        </div>
    </section>
@endsection
