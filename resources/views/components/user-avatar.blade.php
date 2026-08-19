@props(['user', 'size' => 'md'])

@if ($user->avatarUrl())
    <img
        src="{{ $user->avatarUrl() }}"
        class="user-avatar user-avatar-{{ $size }}"
        alt="{{ $user->name }}'s profile image"
    >
@else
    <span
        class="user-avatar user-avatar-{{ $size }} user-avatar-initials"
        role="img"
        aria-label="{{ $user->name }}'s default profile avatar"
    >
        {{ $user->initials() }}
    </span>
@endif
