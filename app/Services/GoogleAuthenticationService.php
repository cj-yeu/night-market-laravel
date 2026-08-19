<?php

namespace App\Services;

use App\Exceptions\SocialAuthenticationException;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Auth\AuthManager;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Contracts\Auth\StatefulGuard;
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

        $linkedAccount = SocialAccount::query()
            ->with('user')
            ->where('provider', SocialAccount::PROVIDER_GOOGLE)
            ->where('provider_user_id', $identity['provider_user_id'])
            ->first();

        if ($linkedAccount) {
            $user = $linkedAccount->user;
            $this->ensureActiveClient($user);

            if ($linkedAccount->provider_email !== $identity['email']) {
                $linkedAccount->update(['provider_email' => $identity['email']]);
            }

            $this->guard()->login($user);

            return $user;
        }

        if (User::query()->where('email', $identity['email'])->exists()) {
            throw new SocialAuthenticationException(
                'An account already uses this email address. Log in with your password and connect Google from Profile.',
            );
        }

        $user = DB::transaction(function () use ($identity): User {
            $user = User::create([
                'name' => $identity['name'],
                'email' => $identity['email'],
                'password' => null,
                'role' => User::ROLE_CLIENT,
                'is_active' => true,
            ]);

            $user->socialAccounts()->create([
                'provider' => SocialAccount::PROVIDER_GOOGLE,
                'provider_user_id' => $identity['provider_user_id'],
                'provider_email' => $identity['email'],
            ]);

            return $user;
        });

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
        $this->ensureActiveClient($user);
        $identity = $this->validatedIdentity($providerUser);

        if (! hash_equals(mb_strtolower($user->email), $identity['email'])) {
            throw new SocialAuthenticationException(
                'The Google email address must match your current account email.',
            );
        }

        $providerAccount = SocialAccount::query()
            ->where('provider', SocialAccount::PROVIDER_GOOGLE)
            ->where('provider_user_id', $identity['provider_user_id'])
            ->first();

        if ($providerAccount) {
            $message = $providerAccount->user_id === $user->id
                ? 'A Google account is already connected to this profile.'
                : 'This Google account is already connected to another profile.';

            throw new SocialAuthenticationException($message);
        }

        if ($user->googleAccount()->exists()) {
            throw new SocialAuthenticationException('A Google account is already connected to this profile.');
        }

        return $user->socialAccounts()->create([
            'provider' => SocialAccount::PROVIDER_GOOGLE,
            'provider_user_id' => $identity['provider_user_id'],
            'provider_email' => $identity['email'],
        ]);
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
     * @return array{provider_user_id: string, email: string, name: string}
     */
    private function validatedIdentity(SocialiteUser $providerUser): array
    {
        $providerUserId = trim((string) $providerUser->getId());
        $email = mb_strtolower(trim((string) $providerUser->getEmail()));

        if ($providerUserId === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new SocialAuthenticationException(
                'Google did not provide the account information required to continue.',
            );
        }

        $raw = method_exists($providerUser, 'getRaw') ? $providerUser->getRaw() : [];
        $verification = $raw['email_verified'] ?? $raw['verified_email'] ?? null;

        if ($verification !== null && filter_var($verification, FILTER_VALIDATE_BOOL) !== true) {
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
        ];
    }

    private function ensureActiveClient(User $user): void
    {
        if ($user->role !== User::ROLE_CLIENT || ! $user->is_active) {
            throw new SocialAuthenticationException(
                'Google authentication is not available for this account.',
            );
        }
    }

    private function guard(): StatefulGuard
    {
        /** @var StatefulGuard&Guard $guard */
        $guard = $this->auth->guard('web');

        return $guard;
    }
}
