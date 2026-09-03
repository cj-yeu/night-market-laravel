<?php

namespace App\Services;

use App\Models\CatalogAuditLog;
use App\Models\Food;
use App\Models\NightMarket;
use App\Models\Stall;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;

class CatalogAuditLogService
{
    /** @var array<string, string> */
    private const SAFE_FIELDS = [
        'name' => 'Name', 'city' => 'City', 'category' => 'Category', 'halal_status' => 'Halal status',
        'source_url' => 'Source URL', 'verified_at' => 'Last verified', 'price_min' => 'Minimum price',
        'price_max' => 'Maximum price', 'price_display' => 'Price display', 'price_checked_at' => 'Price checked',
        'is_must_try' => 'Must-Try', 'operating_days' => 'Operating days', 'status' => 'Status',
    ];

    public function record(User $user, Model $entity, string $action, string $summary, array $changedFields = []): void
    {
        CatalogAuditLog::create([
            'user_id' => $user->id,
            'entity_type' => $this->entityType($entity),
            'entity_id' => $entity->getKey(),
            'action' => $action,
            'summary' => $summary,
            'changed_fields' => $changedFields ?: null,
        ]);
    }

    public function recordDeleted(User $user, string $entityType, int $entityId, string $name): void
    {
        CatalogAuditLog::create([
            'user_id' => $user->id,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'action' => 'deleted',
            'summary' => 'Deleted '.str_replace('_', ' ', $entityType).' “'.str($name)->limit(160, '')->value().'”',
            'changed_fields' => null,
        ]);
    }

    /** @param array<string, mixed> $before */
    public function safeChanges(array $before, Model $entity, array $extra = []): array
    {
        $changes = [];
        foreach (self::SAFE_FIELDS as $field => $label) {
            if ($field === 'operating_days' || ! array_key_exists($field, $before)) {
                continue;
            }
            $after = $entity->getAttribute($field);
            if ($this->display($before[$field]) !== $this->display($after)) {
                $changes[$field] = ['label' => $label, 'before' => $this->display($before[$field]), 'after' => $this->display($after)];
            }
        }

        foreach (['address', 'description', 'night_market_id', 'stall_id', 'halal_evidence_url', 'halal_notes', 'recommendation_reason'] as $field) {
            if (array_key_exists($field, $before) && $this->display($before[$field]) !== $this->display($entity->getAttribute($field))) {
                $changes['catalog_details'] = ['label' => 'Catalog details', 'before' => 'Updated', 'after' => 'Updated'];
                break;
            }
        }

        return [...$changes, ...$extra];
    }

    /** @param array{entity_type?: string|null, action?: string|null, search?: string|null} $filters */
    public function paginate(array $filters): LengthAwarePaginator
    {
        $search = $this->literalLikePattern($filters['search'] ?? null);

        return CatalogAuditLog::query()->with('user:id,name')
            ->when($filters['entity_type'] ?? null, fn ($query, $value) => $query->where('entity_type', $value))
            ->when($filters['action'] ?? null, fn ($query, $value) => $query->where('action', $value))
            ->when($search, fn ($query, $value) => $query->where(fn ($q) => $q->where('summary', 'like', $value)))
            ->latest('created_at')->latest('id')->paginate(20)->withQueryString();
    }

    public function entityType(Model $entity): string
    {
        return match ($entity::class) {
            NightMarket::class => CatalogAuditLog::ENTITY_MARKET,
            Stall::class => CatalogAuditLog::ENTITY_STALL,
            Food::class => CatalogAuditLog::ENTITY_FOOD,
            User::class => CatalogAuditLog::ENTITY_USER,
        };
    }

    private function display(mixed $value): string|int|float|bool|null
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        if (is_bool($value) || is_int($value) || is_float($value) || $value === null) {
            return $value;
        }

        return str((string) $value)->limit(120)->value();
    }

    private function literalLikePattern(?string $value): ?string
    {
        return $value ? '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value).'%' : null;
    }
}
