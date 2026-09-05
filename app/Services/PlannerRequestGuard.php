<?php

namespace App\Services;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

class PlannerRequestGuard
{
    public function cache(): Repository
    {
        // Reuse the application's durable store (including existing DB cache
        // tables). Ephemeral array/null stores cannot protect separate requests.
        $store = config('cache.default');
        if (in_array(config('cache.stores.'.$store.'.driver'), ['array', 'null', 'octane'], true)) {
            $store = 'file';
        }

        // If this store fails, requests fail closed, not an unprotected API call.
        return Cache::store($store);
    }

    public function run(int $userId, callable $operation): mixed
    {
        try {
            $lock = $this->cache()->lock('planner-user:'.$userId, 120);
            $acquired = $lock->get();
        } catch (\Throwable) {
            throw ValidationException::withMessages(['planner' => 'Planner request protection is unavailable. Please try again later.']);
        }
        try {
            if (! $acquired) {
                throw ValidationException::withMessages(['planner' => 'A planner request is already running. Please wait before trying again.']);
            }

            return $operation();
        } finally {
            $lock->release();
        }
    }

    public function charge(int $userId): void
    {
        $key = 'planner-rate:'.$userId;
        $window = $this->cache()->get($key, ['until' => 0, 'count' => 0]);
        if ($window['until'] <= time()) {
            $window = ['until' => time() + 60, 'count' => 0];
        }
        if ($window['count'] >= 4) {
            throw ValidationException::withMessages(['planner' => 'Please wait a minute before asking AI again. Your preferences have been kept.']);
        }
        $window['count']++;
        if (! $this->cache()->put($key, $window, 60)) {
            throw ValidationException::withMessages(['planner' => 'Planner request protection is unavailable. Please try again later.']);
        }
    }
}
