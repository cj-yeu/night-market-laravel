<?php

namespace App\Services;

use App\Exceptions\PlannerAiUnavailable;
use App\Models\Stall;
use App\Support\PlannerFoodInterests;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class PlannerPreferenceParser
{
    public function __construct(private readonly OpenAiPlannerProvider $provider, private readonly PlannerRequestGuard $guard) {}

    public function parse(int $userId, string $text, array $cities, array $availableInterests): array
    {
        return $this->guard->run($userId, function () use ($userId, $text, $cities, $availableInterests) {
            $key = 'planner-parse:'.$userId.':'.hash('sha256', json_encode([$text, $cities, $availableInterests, now('Asia/Kuala_Lumpur')->toDateString()]));
            if ($cached = $this->guard->cache()->get($key)) {
                return $cached;
            }
            try {
                $this->guard->charge($userId);
                $data = $this->provider->parse($text, $cities);
                $rules = [
                    'date_kind' => ['required', Rule::in(['unspecified', 'today', 'tomorrow', 'absolute'])],
                    'date' => ['present', 'nullable', 'date_format:Y-m-d'],
                    'city' => ['present', 'nullable', Rule::in($cities)],
                    'budget_max' => ['present', 'nullable', 'numeric', 'min:0', 'max:10000'],
                    'halal_preference' => ['present', 'nullable', Rule::in(['any', ...Stall::HALAL_STATUSES])],
                    'interests' => ['present', 'array', 'max:7'],
                    'interests.*' => ['string', 'distinct', Rule::in(array_keys(PlannerFoodInterests::GROUPS))],
                    'unsupported' => ['required', 'boolean'],
                ];
                if (Validator::make($data, $rules)->fails() || array_diff(array_keys($data), array_keys(array_filter($rules, fn ($key) => ! str_contains($key, '.'), ARRAY_FILTER_USE_KEY))) !== []) {
                    throw new PlannerAiUnavailable;
                }
                $today = CarbonImmutable::today('Asia/Kuala_Lumpur');
                $date = match ($data['date_kind']) {
                    'today' => $today->toDateString(),
                    'tomorrow' => $today->addDay()->toDateString(),
                    'absolute' => $data['date'],
                    default => null,
                };
                // Validate unambiguous relative language against the server clock,
                // even when the model incorrectly emits an absolute date.
                $mentionsToday = preg_match('/\b(today|tonight)\b|今天|今晚/iu', $text) === 1;
                $mentionsTomorrow = preg_match('/\btomorrow\b|明天|明晚/iu', $text) === 1;
                if ($mentionsToday xor $mentionsTomorrow) {
                    $date = ($mentionsTomorrow ? $today->addDay() : $today)->toDateString();
                } elseif ($mentionsToday && $mentionsTomorrow) {
                    $date = null;
                    $data['unsupported'] = true;
                }
                if (($data['date_kind'] === 'absolute' && ! $date) || ($date && $date < $today->toDateString())) {
                    throw new PlannerAiUnavailable;
                }
                $unavailable = array_diff($data['interests'], $availableInterests);
                $result = [
                    'preferences' => ['visit_date' => $date, 'city' => $data['city'], 'budget_max' => $data['budget_max'],
                        'halal_preference' => $data['halal_preference'], 'interests' => array_values(array_intersect($data['interests'], $availableInterests))],
                    'notice' => $data['unsupported'] || $unavailable !== []
                        ? 'Some requests are unsupported or ambiguous. We cannot verify allergies, spice levels, facilities, live opening or unlisted locations. Review the supported suggestions below; no preference has been changed.'
                        : 'Review each suggestion before applying it. Your current preferences have not changed.',
                ];
                $this->guard->cache()->put($key, $result, 180);

                return $result;
            } catch (PlannerAiUnavailable) {
                return ['preferences' => null, 'notice' => 'AI could not safely interpret this request. Please set the date, city, budget and food interests manually.'];
            }
        });
    }
}
