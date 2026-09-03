@props(['user', 'size' => 'md'])

@if ($user->avatarUrl())
    <span class="user-avatar user-avatar-{{ $size }}" role="img" aria-label="{{ $user->name }}'s profile image">
        <img src="{{ $user->avatarUrl() }}" class="user-avatar-image" alt="" onerror="this.hidden = true; this.nextElementSibling.hidden = false;">
        <span class="user-avatar-initials" hidden>{{ $user->initials() }}</span>
    </span>
@else
    <span
        class="user-avatar user-avatar-{{ $size }} user-avatar-initials"
        role="img"
        aria-label="{{ $user->name }}'s default profile avatar"
    >
        {{ $user->initials() }}
    </span>
@endif
