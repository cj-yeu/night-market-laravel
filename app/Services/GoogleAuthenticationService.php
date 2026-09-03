<?php

namespace App\Services;

use App\Exceptions\SocialAuthenticationException;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Auth\AuthManager;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Socialite\Contracts\User as SocialiteUser;

class GoogleAuthenticationService
{
    public const SESSION_INTENT = 'google_oauth_intent';

    public function __construct(private readonly AuthManager $auth) {}

    public function login(SocialiteUser $providerUser): User
    {
        $identity = $this->validatedIdentity($providerUser);

        try {
            $user = DB::transaction(
                fn (): User => $this->resolveLoginIdentity($identity),
                3,
            );
        } catch (UniqueConstraintViolationException) {
            // A simultaneous callback may have created the user or binding after
            // our first read. Resolve once more through the database constraints.
            try {
                $user = DB::transaction(
                    fn (): User => $this->resolveLoginIdentity($identity),
                    3,
                );
            } catch (UniqueConstraintViolationException) {
                throw new SocialAuthenticationException(
                    'Google authentication is already being completed for this account. Please try again.',
                );
            }
        }

        $this->guard()->login($user);

        return $user;
    }

    public function confirmLinkStart(User $user, ?string $currentPassword): void
    {
        $this->ensureActiveClient($user);

        if ($user->googleAccount()->exists()) {
            throw new SocialAuthenticationException('A Google account is already connected to this profile.');
        }

        if ($user->password !== null
            && ($currentPassword === null || ! Hash::check($currentPassword, $user->password))) {
            throw new SocialAuthenticationException('The current password is incorrect.');
        }
    }

    public function link(User $user, SocialiteUser $providerUser): SocialAccount
    {
        $identity = $this->validatedIdentity($providerUser);

        if (! hash_equals($this->normalizeEmail($user->email), $identity['email'])) {
            throw new SocialAuthenticationException(
                'The Google email address must match your current account email.',
            );
        }

        try {
            return DB::transaction(function () use ($user, $identity): SocialAccount {
                $providerAccount = SocialAccount::query()
                    ->where('provider', SocialAccount::PROVIDER_GOOGLE)
                    ->where('provider_user_id', $identity['provider_user_id'])
                    ->lockForUpdate()
                    ->first();
                $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);

                $this->ensureActiveClient($lockedUser);

                if (! hash_equals($this->normalizeEmail($lockedUser->email), $identity['email'])) {
                    throw new SocialAuthenticationException(
                        'The Google email address must match your current account email.',
                    );
                }

                if ($providerAccount) {
                    if ($providerAccount->user_id !== $lockedUser->id) {
                        throw new SocialAuthenticationException(
                            'This Google account is already connected to another profile.',
                        );
                    }

                    $this->markEmailVerified($lockedUser);
                    $this->storeGoogleAvatarWhenSafe($lockedUser, $identity['avatar_url']);

                    return $providerAccount;
                }

                $existingAccount = SocialAccount::query()
                    ->where('user_id', $lockedUser->id)
                    ->where('provider', SocialAccount::PROVIDER_GOOGLE)
                    ->lockForUpdate()
                    ->first();

                if ($existingAccount) {
                    throw new SocialAuthenticationException(
                        'A Google account is already connected to this profile.',
                    );
                }

                $account = $lockedUser->socialAccounts()->create([
                    'provider' => SocialAccount::PROVIDER_GOOGLE,
                    'provider_user_id' => $identity['provider_user_id'],
                    'provider_email' => $identity['email'],
                ]);

                $this->markEmailVerified($lockedUser);
                $this->storeGoogleAvatarWhenSafe($lockedUser, $identity['avatar_url']);

                return $account;
            }, 3);
        } catch (UniqueConstraintViolationException) {
            $account = SocialAccount::query()
                ->where('provider', SocialAccount::PROVIDER_GOOGLE)
                ->where('provider_user_id', $identity['provider_user_id'])
                ->first();

            if ($account?->user_id === $user->id) {
                $this->markEmailVerified($user);

                return $account;
            }

            throw new SocialAuthenticationException(
                'This Google account is already connected to another profile.',
            );
        }
    }

    public function disconnect(User $user, string $currentPassword): bool
    {
        $this->ensureActiveClient($user);

        if ($user->password === null) {
            throw new SocialAuthenticationException(
                'Set a local password using Forgot Password before disconnecting Google.',
            );
        }

        if (! Hash::check($currentPassword, $user->password)) {
            throw new SocialAuthenticationException('The current password is incorrect.');
        }

        return $user->socialAccounts()
            ->where('provider', SocialAccount::PROVIDER_GOOGLE)
            ->delete() > 0;
    }

    /**
     * @param  array{provider_user_id: string, email: string, name: string, avatar_url: string|null}  $identity
     */
    private function resolveLoginIdentity(array $identity): User
    {
        $linkedAccount = SocialAccount::query()
            ->where('provider', SocialAccount::PROVIDER_GOOGLE)
            ->where('provider_user_id', $identity['provider_user_id'])
            ->lockForUpdate()
            ->first();

        if ($linkedAccount) {
            $user = User::query()->lockForUpdate()->findOrFail($linkedAccount->user_id);
            $this->ensureActiveGoogleUser($user);

            if (! hash_equals($this->normalizeEmail($user->email), $identity['email'])) {
                throw new SocialAuthenticationException(
                    'This Google identity conflicts with another account.',
                );
            }

            if ($linkedAccount->provider_email !== $identity['email']) {
                $linkedAccount->update(['provider_email' => $identity['email']]);
            }

            $this->markEmailVerified($user);
            $this->storeGoogleAvatarWhenSafe($user, $identity['avatar_url']);

            return $user;
        }

        $user = User::query()
            ->where('email', $identity['email'])
            ->lockForUpdate()
            ->first();

        if ($user) {
            $this->ensureActiveGoogleUser($user);

            if ($user->hasAdminAccess()) {
                throw new SocialAuthenticationException(
                    'This Admin account is not connected to Google. Sign in with your password instead.',
                );
            }

            $existingAccount = SocialAccount::query()
                ->where('user_id', $user->id)
                ->where('provider', SocialAccount::PROVIDER_GOOGLE)
                ->lockForUpdate()
                ->first();

            if ($existingAccount) {
                throw new SocialAuthenticationException(
                    'A Google account is already connected to this profile.',
                );
            }

            $user->socialAccounts()->create([
                'provider' => SocialAccount::PROVIDER_GOOGLE,
                'provider_user_id' => $identity['provider_user_id'],
                'provider_email' => $identity['email'],
            ]);
            $this->markEmailVerified($user);
            $this->storeGoogleAvatarWhenSafe($user, $identity['avatar_url']);

            return $user;
        }

        $user = new User([
            'name' => $identity['name'],
            'email' => $identity['email'],
            'password' => null,
            'role' => User::ROLE_CLIENT,
            'is_active' => true,
        ]);
        $user->email_verified_at = now();
        $user->save();
        $this->storeGoogleAvatarWhenSafe($user, $identity['avatar_url']);

        $user->socialAccounts()->create([
            'provider' => SocialAccount::PROVIDER_GOOGLE,
            'provider_user_id' => $identity['provider_user_id'],
            'provider_email' => $identity['email'],
        ]);

        return $user;
    }

    /**
     * @return array{provider_user_id: string, email: string, name: string, avatar_url: string|null}
     */
    private function validatedIdentity(SocialiteUser $providerUser): array
    {
        $providerUserId = trim((string) $providerUser->getId());
        $email = $this->normalizeEmail((string) $providerUser->getEmail());

        if ($providerUserId === '' || mb_strlen($providerUserId) > 255
            || mb_strlen($email) > 255 || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new SocialAuthenticationException(
                'Google did not provide the account information required to continue.',
            );
        }

        $raw = method_exists($providerUser, 'getRaw') ? $providerUser->getRaw() : [];
        $verification = $raw['email_verified'] ?? $raw['verified_email'] ?? null;

        if (filter_var($verification, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) !== true) {
            throw new SocialAuthenticationException(
                'Google authentication requires a verified email address.',
            );
        }

        $name = Str::of((string) $providerUser->getName())->squish()->limit(255, '')->value();

        if ($name === '') {
            $name = Str::of(Str::before($email, '@'))
                ->replace(['.', '_', '-'], ' ')
                ->squish()
                ->title()
                ->limit(255, '')
                ->value();
        }

        return [
            'provider_user_id' => $providerUserId,
            'email' => $email,
            'name' => $name !== '' ? $name : 'Google User',
            'avatar_url' => $this->safeGoogleAvatarUrl((string) $providerUser->getAvatar()),
        ];
    }

    private function storeGoogleAvatarWhenSafe(User $user, ?string $avatarUrl): void
    {
        if ($user->avatar_path !== null || $avatarUrl === null || $user->google_avatar_url === $avatarUrl) {
            return;
        }

        $user->forceFill(['google_avatar_url' => $avatarUrl])->save();
    }

    private function safeGoogleAvatarUrl(string $url): ?string
    {
        $url = trim($url);

        return User::isTrustedGoogleAvatarUrl($url) ? $url : null;
    }

    private function markEmailVerified(User $user): void
    {
        if (! $user->hasVerifiedEmail()) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }
    }

    private function ensureActiveGoogleUser(User $user): void
    {
        if (! $user->is_active) {
            throw new SocialAuthenticationException(
                'This account is inactive. Please contact an administrator.',
            );
        }

        if ($user->role !== User::ROLE_CLIENT && ! $user->hasAdminAccess()) {
            throw new SocialAuthenticationException(
                'Google authentication is not available for this account.',
            );
        }
    }

    private function ensureActiveClient(User $user): void
    {
        if ($user->role !== User::ROLE_CLIENT || ! $user->is_active) {
            throw new SocialAuthenticationException(
                'Google account connection is not available for this account.',
            );
        }
    }

    private function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    private function guard(): StatefulGuard
    {
        /** @var StatefulGuard&Guard $guard */
        $guard = $this->auth->guard('web');

        return $guard;
    }
}
