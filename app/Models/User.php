<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail as MustVerifyEmailContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class User extends Authenticatable implements MustVerifyEmailContract
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public const ROLE_ADMIN = 'admin';

    public const ROLE_SUPER_ADMIN = 'super_admin';

    public const ROLE_CLIENT = 'client';

    public const ADMIN_ROLES = [self::ROLE_ADMIN, self::ROLE_SUPER_ADMIN];

    public const AUTH_PASSWORD = 'password';

    public const AUTH_GOOGLE = 'google';

    public const AUTH_PASSWORD_AND_GOOGLE = 'password_and_google';

    public const AUTH_UNAVAILABLE = 'unavailable';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'is_active',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'has_local_password' => 'boolean',
        ];
    }

    public function scopeSearch(Builder $query, ?string $search): Builder
    {
        if ($search === null || $search === '') {
            return $query;
        }

        $escapedSearch = addcslashes($search, '\\%_');

        return $query->where(function (Builder $query) use ($escapedSearch): void {
            $query->where('name', 'like', '%'.$escapedSearch.'%')
                ->orWhere('email', 'like', '%'.$escapedSearch.'%');
        });
    }

    public function hasRole(string $role): bool
    {
        return $role === self::ROLE_ADMIN
            ? in_array($this->role, self::ADMIN_ROLES, true)
            : $this->role === $role;
    }

    public function hasAdminAccess(): bool
    {
        return $this->hasRole(self::ROLE_ADMIN);
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === self::ROLE_SUPER_ADMIN;
    }

    public function scopeWithRole(Builder $query, ?string $role): Builder
    {
        return $role === null ? $query : $query->where('role', $role);
    }

    public function scopeWithActiveStatus(Builder $query, ?string $status): Builder
    {
        return $status === null ? $query : $query->where('is_active', $status === 'active');
    }

    public function scopeWithVerificationStatus(Builder $query, ?string $verification): Builder
    {
        if ($verification === 'verified') {
            return $query->whereNotNull('email_verified_at');
        }

        if ($verification === 'pending') {
            return $query->whereNull('email_verified_at');
        }

        return $query;
    }

    public function scopeWithAuthenticationMethod(Builder $query, ?string $method): Builder
    {
        return match ($method) {
            self::AUTH_PASSWORD => $query->whereNotNull('password')->whereDoesntHave('googleAccount'),
            self::AUTH_GOOGLE => $query->whereNull('password')->whereHas('googleAccount'),
            self::AUTH_PASSWORD_AND_GOOGLE => $query->whereNotNull('password')->whereHas('googleAccount'),
            default => $query,
        };
    }

    public function visitPlans(): HasMany
    {
        return $this->hasMany(VisitPlan::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    public function approvedSocialMediaRecords(): HasMany
    {
        return $this->hasMany(SocialMediaRecord::class, 'approved_by');
    }

    public function createdCatalogImportProposals(): HasMany
    {
        return $this->hasMany(CatalogImportProposal::class, 'created_by');
    }

    public function reviewedCatalogImportProposals(): HasMany
    {
        return $this->hasMany(CatalogImportProposal::class, 'reviewed_by');
    }

    public function socialAccounts(): HasMany
    {
        return $this->hasMany(SocialAccount::class);
    }

    public function googleAccount(): HasOne
    {
        return $this->hasOne(SocialAccount::class)
            ->where('provider', SocialAccount::PROVIDER_GOOGLE);
    }

    public function googleCalendarConnection(): HasOne
    {
        return $this->hasOne(GoogleCalendarConnection::class);
    }

    public function googleCalendarEvents(): HasMany
    {
        return $this->hasMany(GoogleCalendarEvent::class);
    }

    public function authenticationMethod(): string
    {
        $hasPassword = array_key_exists('has_local_password', $this->attributes)
            ? (bool) $this->has_local_password
            : $this->password !== null;
        $hasGoogle = $this->relationLoaded('googleAccount')
            ? $this->googleAccount !== null
            : $this->googleAccount()->exists();

        return match (true) {
            $hasPassword && $hasGoogle => self::AUTH_PASSWORD_AND_GOOGLE,
            $hasPassword => self::AUTH_PASSWORD,
            $hasGoogle => self::AUTH_GOOGLE,
            default => self::AUTH_UNAVAILABLE,
        };
    }

    public function authenticationMethodLabel(): string
    {
        return match ($this->authenticationMethod()) {
            self::AUTH_PASSWORD => 'Password',
            self::AUTH_GOOGLE => 'Google',
            self::AUTH_PASSWORD_AND_GOOGLE => 'Password + Google',
            default => 'Unavailable',
        };
    }

    public function avatarUrl(): ?string
    {
        if ($this->avatar_path) {
            return Storage::disk('public')->url($this->avatar_path);
        }

        return self::isTrustedGoogleAvatarUrl($this->google_avatar_url ?? null)
            ? $this->google_avatar_url
            : null;
    }

    public static function isTrustedGoogleAvatarUrl(?string $url): bool
    {
        if (! is_string($url) || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        if (strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https') {
            return false;
        }

        if (parse_url($url, PHP_URL_USER) !== null || parse_url($url, PHP_URL_PASS) !== null) {
            return false;
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return $host !== '' && (
            $host === 'googleusercontent.com'
            || str_ends_with($host, '.googleusercontent.com')
            || $host === 'ggpht.com'
            || str_ends_with($host, '.ggpht.com')
        );
    }

    public function initials(): string
    {
        $initials = Str::of((string) $this->name)
            ->squish()
            ->explode(' ')
            ->filter()
            ->take(2)
            ->map(fn (string $part) => mb_strtoupper(mb_substr($part, 0, 1)))
            ->implode('');

        return $initials !== '' ? $initials : '?';
    }
}
