<?php

namespace App\Services;

use App\Models\Food;
use App\Models\Stall;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class StallFoodImageService
{
    private const DISK = 'public';

    public function updateStallImage(Stall $stall, UploadedFile $image): void
    {
        $this->replaceImage($stall, $image, Stall::IMAGE_DIRECTORY, 'stall');
    }

    public function removeStallImage(Stall $stall): void
    {
        $this->removeImage($stall, 'stall');
    }

    public function updateFoodImage(Food $food, UploadedFile $image): void
    {
        $this->replaceImage($food, $image, Food::IMAGE_DIRECTORY, 'food');
    }

    public function removeFoodImage(Food $food): void
    {
        $this->removeImage($food, 'food');
    }

    private function replaceImage(Stall|Food $record, UploadedFile $image, string $directory, string $label): void
    {
        $extension = match ($image->getMimeType()) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => null,
        };

        if ($extension === null) {
            throw ValidationException::withMessages([
                'image' => 'The image must be a valid JPEG, PNG, or WebP image.',
            ]);
        }

        try {
            $newPath = $image->storeAs($directory, Str::uuid().'.'.$extension, self::DISK);
        } catch (Throwable) {
            $newPath = false;
        }

        if (! $newPath) {
            throw ValidationException::withMessages([
                'image' => 'The image could not be stored. Please try again.',
            ]);
        }

        $previousPath = $record->image_path;

        try {
            if (! $record->forceFill(['image_path' => $newPath])->saveOrFail()) {
                throw new \RuntimeException('Image path was not persisted.');
            }
        } catch (Throwable) {
            Storage::disk(self::DISK)->delete($newPath);
            $record->forceFill(['image_path' => $previousPath]);

            throw ValidationException::withMessages([
                'image' => 'The image could not be saved. The existing image was not changed.',
            ]);
        }

        $disk = Storage::disk(self::DISK);

        if ($record::isOwnedImagePath($previousPath)
            && $disk->exists($previousPath)
            && ! $disk->delete($previousPath)) {
            Log::warning('Catalog image replacement left an old owned file for later cleanup.', [
                'entity' => $label,
                'record_id' => $record->getKey(),
            ]);
        }
    }

    private function removeImage(Stall|Food $record, string $label): void
    {
        $path = $record->image_path;

        if ($path === null) {
            return;
        }

        try {
            if (! $record->forceFill(['image_path' => null])->saveOrFail()) {
                throw new \RuntimeException('Image path was not cleared.');
            }
        } catch (Throwable) {
            $record->forceFill(['image_path' => $path]);

            throw ValidationException::withMessages([
                'image' => 'The image could not be removed. Please try again.',
            ]);
        }

        $disk = Storage::disk(self::DISK);

        if ($record::isOwnedImagePath($path)
            && $disk->exists($path)
            && ! $disk->delete($path)) {
            try {
                $record->forceFill(['image_path' => $path])->saveOrFail();
            } catch (Throwable) {
                Log::warning('Catalog image removal could not restore its database path.', [
                    'entity' => $label,
                    'record_id' => $record->getKey(),
                ]);
            }

            throw ValidationException::withMessages([
                'image' => 'The image could not be removed. Please try again.',
            ]);
        }
    }
}
