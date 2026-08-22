<aside class="offcanvas-lg offcanvas-start admin-sidebar" tabindex="-1" id="adminSidebar"
    aria-labelledby="adminSidebarLabel">
    <div class="offcanvas-header border-bottom border-light border-opacity-25 px-4 py-3">
        <a id="adminSidebarLabel" class="admin-sidebar-brand d-flex align-items-center gap-3 text-decoration-none"
            href="{{ route('admin.dashboard') }}">
            <span class="admin-brand-mark" aria-hidden="true"><i class="bi bi-shop-window"></i></span>
            <span>
                <span class="d-block">Night Market</span>
                <span class="admin-supporting-text d-block small fw-normal">Admin Portal</span>
            </span>
        </a>
        <button type="button" class="btn-close btn-close-white d-lg-none" data-bs-dismiss="offcanvas"
            data-bs-target="#adminSidebar" aria-label="Close navigation"></button>
    </div>

    <div class="offcanvas-body d-flex flex-column p-3">
        <nav class="nav nav-pills flex-column" aria-label="Admin navigation">
            <div class="admin-nav-heading">OVERVIEW</div>
            <a class="nav-link admin-sidebar-link {{ request()->routeIs('admin.dashboard') ? 'active' : '' }}"
                href="{{ route('admin.dashboard') }}">
                <i class="bi bi-grid-1x2-fill" aria-hidden="true"></i>Dashboard
            </a>

            <div class="admin-nav-heading">MANAGEMENT</div>
            <a class="nav-link admin-sidebar-link {{ request()->routeIs('admin.users.*') ? 'active' : '' }}"
                href="{{ route('admin.users.index') }}">
                <i class="bi bi-people-fill" aria-hidden="true"></i>Users
            </a>

            <a class="nav-link admin-sidebar-link {{ request()->routeIs('admin.night-markets.*') ? 'active' : '' }}"
                href="{{ route('admin.night-markets.index') }}">
                <i class="bi bi-shop" aria-hidden="true"></i>Night Markets
            </a>

            <a class="nav-link admin-sidebar-link {{ request()->routeIs('admin.stalls.*') ? 'active' : '' }}"
                href="{{ route('admin.stalls.index') }}">
                <i class="bi bi-basket-fill" aria-hidden="true"></i>Stalls
            </a>

            <a class="nav-link admin-sidebar-link {{ request()->routeIs('admin.foods.*') ? 'active' : '' }}"
                href="{{ route('admin.foods.index') }}">
                <i class="bi bi-cup-hot-fill" aria-hidden="true"></i>Foods
            </a>

            <a class="nav-link admin-sidebar-link {{ request()->routeIs('admin.reviews.*') ? 'active' : '' }}"
                href="{{ route('admin.reviews.index') }}">
                <i class="bi bi-star-fill" aria-hidden="true"></i>Reviews
            </a>

            <a class="nav-link admin-sidebar-link {{ request()->routeIs('admin.social-media-records.*') ? 'active' : '' }}"
                href="{{ route('admin.social-media-records.index') }}">
                <i class="bi bi-megaphone-fill" aria-hidden="true"></i>Social Media
            </a>

            <div class="admin-nav-heading">QUICK ACTIONS</div>
            @php($createMenuOpen = request()->routeIs('admin.night-markets.*', 'admin.stalls.*', 'admin.foods.*'))
            <button class="nav-link admin-sidebar-link d-flex justify-content-between align-items-center"
                type="button" data-bs-toggle="collapse" data-bs-target="#adminCreateMenu"
                aria-expanded="{{ $createMenuOpen ? 'true' : 'false' }}" aria-controls="adminCreateMenu">
                <span><i class="bi bi-plus-square-fill" aria-hidden="true"></i>Create New</span>
                <i class="bi bi-chevron-down admin-menu-chevron" aria-hidden="true"></i>
            </button>
            <div class="collapse {{ $createMenuOpen ? 'show' : '' }}" id="adminCreateMenu">
                <div class="nav flex-column admin-sidebar-submenu py-1">
                    <a class="nav-link {{ request()->routeIs('admin.night-markets.*') ? 'active' : '' }}"
                        href="{{ route('admin.night-markets.create') }}">Create Night Market</a>
                    <a class="nav-link {{ request()->routeIs('admin.stalls.*') ? 'active' : '' }}"
                        href="{{ route('admin.stalls.create') }}">Create Stall</a>
                    <a class="nav-link {{ request()->routeIs('admin.foods.*') ? 'active' : '' }}"
                        href="{{ route('admin.foods.create') }}">Create Food</a>
                </div>
            </div>
        </nav>

        @php($adminInitial = mb_strtoupper(mb_substr(trim(auth()->user()->name), 0, 1)))
        <div class="admin-account mt-auto p-2">
            <div class="d-flex align-items-center gap-2 px-2 py-2">
                <span class="admin-avatar" aria-hidden="true">{{ $adminInitial }}</span>
                <div class="min-w-0 flex-grow-1">
                    <div class="small fw-semibold text-white text-truncate">{{ auth()->user()->name }}</div>
                    <div class="admin-supporting-text small">Administrator</div>
                </div>
            </div>
            <div class="d-flex gap-1 mt-1">
                <a class="admin-account-link flex-fill px-2 py-2 text-center {{ request()->routeIs('profile.*') ? 'active' : '' }}"
                    href="{{ route('profile.edit') }}">
                    <i class="bi bi-person-circle me-1" aria-hidden="true"></i>Profile
                </a>
                <form method="POST" action="{{ route('logout') }}" class="flex-fill">
                    @csrf
                    <button type="submit" class="admin-account-link admin-logout-link w-100 px-2 py-2">
                        <i class="bi bi-box-arrow-right me-1" aria-hidden="true"></i>Logout
                    </button>
                </form>
            </div>
        </div>
    </div>
</aside>
