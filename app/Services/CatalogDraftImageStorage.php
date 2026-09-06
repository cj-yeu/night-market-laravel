<?php

namespace App\Services;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class CatalogDraftImageStorage
{
    public function disk(): FilesystemAdapter
    {
        $disk = Storage::disk('catalog_drafts');
        $private = $this->canonical($disk->path(''));
        foreach ([public_path(), Storage::disk('public')->path('')] as $root) {
            $public = $this->canonical($root);
            if ($private === $public || str_starts_with($private, $public.'/') || str_starts_with($public, $private.'/')) {
                throw ValidationException::withMessages(['image' => 'Private draft storage must be separate from the public disk and web directory. Ask the administrator to correct the storage configuration.']);
            }
        }

        return $disk;
    }

    private function canonical(string $path): string
    {
        $suffix = [];
        while (! file_exists($path) && dirname($path) !== $path) {
            array_unshift($suffix, basename($path));
            $path = dirname($path);
        }
        $resolved = rtrim(str_replace('\\', '/', realpath($path) ?: $path), '/');
        $resolved .= $suffix ? '/'.implode('/', $suffix) : '';

        return PHP_OS_FAMILY === 'Windows' ? strtolower($resolved) : $resolved;
    }
}
