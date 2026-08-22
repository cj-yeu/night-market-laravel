<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class ProfileImageService
{
    private const DISK = 'public';

    private const DIRECTORY = 'avatars';

    public function replace(User $user, UploadedFile $image): void
    {
        $disk = Storage::disk(self::DISK);
        $filename = Str::uuid().'.'.$image->extension();

        try {
            $newPath = $image->storeAs(self::DIRECTORY, $filename, self::DISK);
        } catch (Throwable) {
            $newPath = false;
        }

        if (! $newPath) {
            throw ValidationException::withMessages([
                'avatar' => 'The profile image could not be stored. Please try again.',
            ]);
        }

        $previousPath = $user->avatar_path;

        try {
            $user->forceFill(['avatar_path' => $newPath])->saveOrFail();
        } catch (Throwable) {
            $disk->delete($newPath);

            throw ValidationException::withMessages([
                'avatar' => 'The profile image could not be saved. Your existing image was not changed.',
            ]);
        }

        if ($this->isOwnedAvatarPath($previousPath)
            && $disk->exists($previousPath)
            && ! $disk->delete($previousPath)) {
            try {
                $user->forceFill(['avatar_path' => $previousPath])->saveOrFail();
                $disk->delete($newPath);
            } catch (Throwable) {
                // Keep the new database path when rollback cannot complete safely.
            }

            throw ValidationException::withMessages([
                'avatar' => 'The existing profile image could not be replaced. Please try again.',
            ]);
        }
    }

    public function remove(User $user): void
    {
        $path = $user->avatar_path;

        if ($path === null) {
            return;
        }

        $user->forceFill(['avatar_path' => null])->saveOrFail();

        $disk = Storage::disk(self::DISK);

        if ($this->isOwnedAvatarPath($path)
            && $disk->exists($path)
            && ! $disk->delete($path)) {
            try {
                $user->forceFill(['avatar_path' => $path])->saveOrFail();
            } catch (Throwable) {
                // Leave the database null when rollback cannot complete safely.
            }

            throw ValidationException::withMessages([
                'avatar' => 'The profile image could not be removed. Please try again.',
            ]);
        }
    }

    private function isOwnedAvatarPath(?string $path): bool
    {
        if ($path === null) {
            return false;
        }

        return preg_match(
            '/\Aavatars\/[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\.(?:jpe?g|png|webp)\z/i',
            str_replace('\\', '/', $path),
        ) === 1;
    }
}
