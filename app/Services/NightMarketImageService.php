<?php

namespace App\Services;

use App\Models\NightMarket;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class NightMarketImageService
{
    private const DISK = 'public';

    public function replace(NightMarket $nightMarket, UploadedFile $image): void
    {
        $disk = Storage::disk(self::DISK);
        $extension = match ($image->getMimeType()) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => null,
        };

        if ($extension === null) {
            throw ValidationException::withMessages([
                'image' => 'The cover image must be a valid JPEG, PNG, or WebP image.',
            ]);
        }

        try {
            $newPath = $image->storeAs(
                NightMarket::IMAGE_DIRECTORY,
                Str::uuid().'.'.$extension,
                self::DISK,
            );
        } catch (Throwable) {
            $newPath = false;
        }

        if (! $newPath) {
            throw ValidationException::withMessages([
                'image' => 'The cover image could not be stored. Please try again.',
            ]);
        }

        $previousPath = $nightMarket->image_path;

        try {
            $nightMarket->forceFill(['image_path' => $newPath])->saveOrFail();
        } catch (Throwable) {
            $disk->delete($newPath);

            throw ValidationException::withMessages([
                'image' => 'The cover image could not be saved. The existing image was not changed.',
            ]);
        }

        if (NightMarket::isOwnedImagePath($previousPath)
            && $disk->exists($previousPath)
            && ! $disk->delete($previousPath)) {
            try {
                $nightMarket->forceFill(['image_path' => $previousPath])->saveOrFail();
                $disk->delete($newPath);
            } catch (Throwable) {
                // Keep the new database path when rollback cannot complete safely.
            }

            throw ValidationException::withMessages([
                'image' => 'The existing cover image could not be replaced. Please try again.',
            ]);
        }
    }

    public function remove(NightMarket $nightMarket): void
    {
        $path = $nightMarket->image_path;

        if ($path === null) {
            return;
        }

        $nightMarket->forceFill(['image_path' => null])->saveOrFail();

        $disk = Storage::disk(self::DISK);

        if (NightMarket::isOwnedImagePath($path)
            && $disk->exists($path)
            && ! $disk->delete($path)) {
            try {
                $nightMarket->forceFill(['image_path' => $path])->saveOrFail();
            } catch (Throwable) {
                // Leave the database null when rollback cannot complete safely.
            }

            throw ValidationException::withMessages([
                'image' => 'The cover image could not be removed. Please try again.',
            ]);
        }
    }
}
