<?php

namespace App\Http\Requests\Concerns;

use App\Services\CatalogSelectionService;
use Illuminate\Validation\Validator;

trait ValidatesCatalogSelection
{
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $field = array_key_exists('market_id', $this->rules()) ? 'market_id' : 'night_market_id';
            foreach (app(CatalogSelectionService::class)->errors($this->validated(), $field) as $key => $message) {
                $validator->errors()->add($key, $message);
            }
        }];
    }
}
