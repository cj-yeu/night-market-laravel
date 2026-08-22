<?php

namespace App\Services;

use App\Models\Food;
use App\Models\MarketOperatingDay;
use App\Models\NightMarket;
use App\Models\Stall;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Throwable;

class CatalogWorkbookImportService
{
    public const DEFAULT_FILE = 'imports/Collab Night Market Cleaned.xlsx';

    public const PRODUCTION_FILE = 'seeders/data/Collab Night Market Cleaned.xlsx';

    public const PRODUCTION_SHA256 = '5486448056b4502ff8480eeff0c7297f2e21f639ce662dceb4fb684d6200817d';

    public const PRODUCTION_COUNTS = [
        'NightMarkets' => 17,
        'MarketSchedules' => 19,
        'Stalls' => 21,
        'Foods' => 21,
    ];

    private const MAX_FILE_BYTES = 5 * 1024 * 1024;

    private const HEADERS = [
        'NightMarkets' => ['market_code', 'market_name', 'local_authority', 'street_address', 'area', 'postcode', 'city', 'state', 'status', 'latitude', 'longitude', 'google_maps_url', 'description', 'source_url', 'source_page_title', 'source_date', 'verified_date', 'notes'],
        'MarketSchedules' => ['market_code', 'day_of_week', 'opening_time', 'closing_time', 'source_url', 'verified_date', 'notes'],
        'Stalls' => ['stall_code', 'market_code', 'stall_name', 'stall_category', 'description', 'halal_status', 'halal_evidence_url', 'status', 'source_url', 'verified_date', 'notes'],
        'Foods' => ['food_code', 'stall_code', 'food_name', 'food_category', 'food_description', 'price_min', 'price_max', 'price_display', 'must_try', 'recommendation_reason', 'status', 'source_url', 'price_checked_date', 'verified_date', 'notes'],
    ];

    private const UNMAPPED = [
        'NightMarkets' => ['local_authority', 'area', 'postcode', 'latitude', 'longitude', 'google_maps_url', 'source_url', 'source_page_title', 'source_date', 'verified_date', 'notes'],
        'MarketSchedules' => ['source_url', 'verified_date', 'notes'],
        'Stalls' => ['notes'],
        'Foods' => ['notes'],
    ];

    /**
     * @return array<string, mixed>
     */
    public function run(string $relativeFile, bool $apply): array
    {
        $path = $this->resolveFile($relativeFile);

        return $this->runResolvedFile($path, $apply, false);
    }

    /** @return array<string, mixed> */
    public function runProduction(bool $apply): array
    {
        $path = $this->resolveProductionFile();

        return $this->runResolvedFile($path, $apply, true);
    }

    /** @return array<string, mixed> */
    private function runResolvedFile(string $path, bool $apply, bool $production): array
    {
        $report = $this->emptyReport($apply);
        $rows = $this->readWorkbook($path, $report);

        if ($report['errors'] !== []) {
            return $report;
        }

        $data = $this->validateRows($rows, $report);

        if ($report['errors'] === [] && $production) {
            $this->validateProductionContract($rows, $data, $report);
        }

        if ($report['errors'] !== []) {
            return $report;
        }

        $plan = $this->buildPlan($data, $report);

        if ($report['errors'] !== [] || ! $apply) {
            return $report;
        }

        try {
            DB::transaction(fn () => $this->applyPlan($data, $plan));
            $report['applied'] = true;
        } catch (Throwable $exception) {
            report($exception);
            $report['errors'][] = 'The import could not be saved. Every catalog change was rolled back.';
            $report['applied'] = false;
        }

        return $report;
    }

    private function resolveProductionFile(): string
    {
        $path = realpath(database_path(self::PRODUCTION_FILE));

        if ($path === false || ! is_file($path)) {
            throw new InvalidArgumentException('The bundled production catalog workbook is missing.');
        }

        $size = filesize($path);
        if ($size === false || $size > self::MAX_FILE_BYTES) {
            throw new InvalidArgumentException('The bundled production catalog workbook exceeds the 5 MB import limit.');
        }

        $hash = hash_file('sha256', $path);
        if ($hash === false || ! hash_equals(self::PRODUCTION_SHA256, Str::lower($hash))) {
            throw new InvalidArgumentException('The bundled production catalog workbook failed its integrity check.');
        }

        return $path;
    }

    private function resolveFile(string $relativeFile): string
    {
        $file = trim(str_replace('\\', '/', $relativeFile));

        if ($file === '' || preg_match('/\A(?:[a-z][a-z0-9+.-]*:|\/|[a-z]:)/i', $file) === 1) {
            throw new InvalidArgumentException('The workbook path must be relative to storage/app/imports.');
        }

        $segments = explode('/', $file);
        if ($segments[0] !== 'imports' || in_array('..', $segments, true) || in_array('.', $segments, true)) {
            throw new InvalidArgumentException('The workbook path must stay inside storage/app/imports.');
        }

        if (strtolower(pathinfo($file, PATHINFO_EXTENSION)) !== 'xlsx') {
            throw new InvalidArgumentException('The workbook must be an .xlsx file.');
        }

        $root = realpath(storage_path('app/imports'));
        $path = realpath(storage_path('app/'.$file));

        if ($root === false || $path === false || ! is_file($path)) {
            throw new InvalidArgumentException("Workbook not found: {$file}");
        }

        $rootPrefix = rtrim(str_replace('\\', '/', $root), '/').'/';
        $normalizedPath = str_replace('\\', '/', $path);
        if (! str_starts_with(strtolower($normalizedPath), strtolower($rootPrefix))) {
            throw new InvalidArgumentException('The workbook path must stay inside storage/app/imports.');
        }

        $size = filesize($path);
        if ($size === false || $size > self::MAX_FILE_BYTES) {
            throw new InvalidArgumentException('The workbook exceeds the 5 MB import limit.');
        }

        return $path;
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<string, list<array<string, mixed>>>
     */
    private function readWorkbook(string $path, array &$report): array
    {
        try {
            $reader = IOFactory::createReader('Xlsx');
            $reader->setReadDataOnly(true);
            $reader->setLoadSheetsOnly(array_keys(self::HEADERS));
            $workbook = $reader->load($path);
        } catch (Throwable) {
            $report['errors'][] = 'The XLSX workbook could not be read.';

            return [];
        }

        $sheetNames = $workbook->getSheetNames();
        foreach (array_keys(self::HEADERS) as $requiredSheet) {
            if (count(array_keys($sheetNames, $requiredSheet, true)) !== 1) {
                $report['errors'][] = "Required sheet {$requiredSheet} must exist exactly once.";
            }
        }

        if ($report['errors'] !== []) {
            $workbook->disconnectWorksheets();

            return [];
        }

        $result = [];
        foreach (self::HEADERS as $sheetName => $expectedHeaders) {
            $sheet = $workbook->getSheetByName($sheetName);
            $highestRow = $sheet->getHighestDataRow();
            $highestColumn = $sheet->getHighestDataColumn();
            $values = $sheet->rangeToArray("A1:{$highestColumn}{$highestRow}", null, false, false);
            $actualHeaders = array_map(fn ($value) => is_string($value) ? $value : (string) $value, array_shift($values) ?? []);

            if ($actualHeaders !== $expectedHeaders || count($actualHeaders) !== count(array_unique($actualHeaders))) {
                $report['errors'][] = "{$sheetName} headers are invalid or out of order. Expected: ".implode(', ', $expectedHeaders).'.';
                $result[$sheetName] = [];

                continue;
            }

            $result[$sheetName] = [];
            foreach ($values as $offset => $valuesRow) {
                if (! collect($valuesRow)->contains(fn ($value) => $value !== null && $value !== '')) {
                    continue;
                }

                $valuesRow = array_pad(array_slice($valuesRow, 0, count($expectedHeaders)), count($expectedHeaders), null);
                $row = array_combine($expectedHeaders, $valuesRow);
                $row['_row'] = $offset + 2;
                $result[$sheetName][] = $row;
            }

            $report['records'][$sheetName] = count($result[$sheetName]);
        }

        $workbook->disconnectWorksheets();

        return $result;
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $rows
     * @param  array<string, mixed>  $report
     * @return array<string, list<array<string, mixed>>>
     */
    private function validateRows(array $rows, array &$report): array
    {
        $data = ['markets' => [], 'schedules' => [], 'stalls' => [], 'foods' => []];
        $marketCodes = $this->uniqueCodes($rows['NightMarkets'], 'market_code', 'NightMarkets', $report);
        $stallCodes = $this->uniqueCodes($rows['Stalls'], 'stall_code', 'Stalls', $report);
        $this->uniqueCodes($rows['Foods'], 'food_code', 'Foods', $report);

        foreach ($rows['NightMarkets'] as $row) {
            $sheet = 'NightMarkets';
            $number = $row['_row'];
            $this->requiredName($row['market_name'], $sheet, $number, 'market_name', $report);
            foreach (['street_address', 'city', 'state'] as $field) {
                $this->requiredString($row[$field], 255, $sheet, $number, $field, $report);
            }
            $this->nullableText($row['description'], $sheet, $number, 'description', $report);
            $status = $this->status($row['status'], $sheet, $number, $report);
            $this->url($row['google_maps_url'], $sheet, $number, 'google_maps_url', $report);
            $this->url($row['source_url'], $sheet, $number, 'source_url', $report);
            $this->date($row['source_date'], $sheet, $number, 'source_date', $report);
            $this->date($row['verified_date'], $sheet, $number, 'verified_date', $report);
            $this->coordinates($row['latitude'], $row['longitude'], $sheet, $number, $report);
            $data['markets'][] = [
                '_row' => $number,
                'code' => $this->string($row['market_code']),
                'attributes' => [
                    'name' => $this->string($row['market_name']),
                    'address' => $this->string($row['street_address']),
                    'city' => $this->string($row['city']),
                    'state' => $this->string($row['state']),
                    'description' => $this->nullableString($row['description']),
                    'status' => $status,
                ],
            ];
        }

        foreach ($rows['MarketSchedules'] as $row) {
            $sheet = 'MarketSchedules';
            $number = $row['_row'];
            $marketCode = $this->requiredCode($row['market_code'], $sheet, $number, 'market_code', $report);
            if ($marketCode !== null && ! isset($marketCodes[$marketCode])) {
                $this->error($report, $sheet, $number, "market_code {$marketCode} does not reference an imported Night Market");
            }
            $day = $this->string($row['day_of_week']);
            if (! in_array($day, MarketOperatingDay::DAYS, true)) {
                $this->error($report, $sheet, $number, 'day_of_week must be an approved English day');
            }
            $opening = $this->time($row['opening_time'], $sheet, $number, 'opening_time', $report);
            $closing = $this->time($row['closing_time'], $sheet, $number, 'closing_time', $report);
            $this->url($row['source_url'], $sheet, $number, 'source_url', $report);
            $this->date($row['verified_date'], $sheet, $number, 'verified_date', $report);
            $data['schedules'][] = ['_row' => $number, 'market_code' => $marketCode, 'day' => $day, 'opening_time' => $opening, 'closing_time' => $closing];
        }

        $scheduleKeys = [];
        foreach ($data['schedules'] as $schedule) {
            $key = $schedule['market_code'].'|'.$schedule['day'];
            if (isset($scheduleKeys[$key])) {
                $this->error($report, 'MarketSchedules', $schedule['_row'], "duplicate Market/day schedule; first seen on row {$scheduleKeys[$key]}");
            } else {
                $scheduleKeys[$key] = $schedule['_row'];
            }
        }

        foreach ($rows['Stalls'] as $row) {
            $sheet = 'Stalls';
            $number = $row['_row'];
            $marketCode = $this->requiredCode($row['market_code'], $sheet, $number, 'market_code', $report);
            if ($marketCode !== null && ! isset($marketCodes[$marketCode])) {
                $this->error($report, $sheet, $number, "market_code {$marketCode} does not reference an imported Night Market");
            }
            $this->requiredName($row['stall_name'], $sheet, $number, 'stall_name', $report);
            $this->nullableStringLimit($row['stall_category'], 255, $sheet, $number, 'stall_category', $report);
            $this->nullableText($row['description'], $sheet, $number, 'description', $report);
            $halal = $this->halalStatus($row['halal_status'], $sheet, $number, $report);
            $this->url($row['halal_evidence_url'], $sheet, $number, 'halal_evidence_url', $report);
            $this->url($row['source_url'], $sheet, $number, 'source_url', $report);
            $verified = $this->date($row['verified_date'], $sheet, $number, 'verified_date', $report);
            $status = $this->status($row['status'], $sheet, $number, $report);
            $data['stalls'][] = [
                '_row' => $number,
                'code' => $this->string($row['stall_code']),
                'market_code' => $marketCode,
                'attributes' => [
                    'name' => $this->string($row['stall_name']),
                    'description' => $this->nullableString($row['description']),
                    'category' => $this->nullableString($row['stall_category']),
                    'halal_status' => $halal,
                    'halal_evidence_url' => $this->nullableString($row['halal_evidence_url']),
                    'source_url' => $this->nullableString($row['source_url']),
                    'verified_at' => $verified,
                    'status' => $status,
                ],
            ];
        }

        foreach ($rows['Foods'] as $row) {
            $sheet = 'Foods';
            $number = $row['_row'];
            $stallCode = $this->requiredCode($row['stall_code'], $sheet, $number, 'stall_code', $report);
            if ($stallCode !== null && ! isset($stallCodes[$stallCode])) {
                $this->error($report, $sheet, $number, "stall_code {$stallCode} does not reference an imported Stall");
            }
            $this->requiredName($row['food_name'], $sheet, $number, 'food_name', $report);
            $this->nullableStringLimit($row['food_category'], 255, $sheet, $number, 'food_category', $report);
            $this->nullableStringLimit($row['price_display'], 255, $sheet, $number, 'price_display', $report);
            $this->nullableText($row['food_description'], $sheet, $number, 'food_description', $report);
            $this->nullableText($row['recommendation_reason'], $sheet, $number, 'recommendation_reason', $report);
            $minimum = $this->price($row['price_min'], $sheet, $number, 'price_min', $report);
            $maximum = $this->price($row['price_max'], $sheet, $number, 'price_max', $report);
            if ($minimum !== null && $maximum !== null && (float) $maximum < (float) $minimum) {
                $this->error($report, $sheet, $number, 'price_max must be greater than or equal to price_min');
            }
            $mustTry = $this->mustTry($row['must_try'], $sheet, $number, $report);
            $this->url($row['source_url'], $sheet, $number, 'source_url', $report);
            $priceChecked = $this->date($row['price_checked_date'], $sheet, $number, 'price_checked_date', $report);
            $verified = $this->date($row['verified_date'], $sheet, $number, 'verified_date', $report);
            $status = $this->status($row['status'], $sheet, $number, $report);
            $data['foods'][] = [
                '_row' => $number,
                'code' => $this->string($row['food_code']),
                'stall_code' => $stallCode,
                'attributes' => [
                    'name' => $this->string($row['food_name']),
                    'description' => $this->nullableString($row['food_description']),
                    'category' => $this->nullableString($row['food_category']),
                    'price_min' => $minimum,
                    'price_max' => $maximum,
                    'price_display' => $this->nullableString($row['price_display']),
                    'is_must_try' => $mustTry,
                    'recommendation_reason' => $this->nullableString($row['recommendation_reason']),
                    'source_url' => $this->nullableString($row['source_url']),
                    'price_checked_at' => $priceChecked,
                    'verified_at' => $verified,
                    'status' => $status,
                ],
            ];
        }

        foreach (['markets', 'schedules', 'stalls', 'foods'] as $entity) {
            $report['counts'][$entity]['validated'] = count($data[$entity]);
        }

        return $data;
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $rows
     * @param  array<string, list<array<string, mixed>>>  $data
     * @param  array<string, mixed>  $report
     */
    private function validateProductionContract(array $rows, array $data, array &$report): void
    {
        foreach (self::PRODUCTION_COUNTS as $sheet => $expected) {
            $actual = count($rows[$sheet] ?? []);

            if ($actual !== $expected) {
                $report['errors'][] = "Production catalog requires exactly {$expected} {$sheet} records; found {$actual}.";
            }
        }

        foreach (['markets', 'stalls', 'foods'] as $entity) {
            $inactiveRows = collect($data[$entity])
                ->filter(fn (array $row): bool => $row['attributes']['status'] !== NightMarket::STATUS_ACTIVE)
                ->pluck('_row')
                ->all();

            if ($inactiveRows !== []) {
                $report['errors'][] = ucfirst($entity).' production rows must all be Active; rejected normalized rows: '
                    .implode(', ', $inactiveRows).'.';
            }
        }

        $nonSelangorRows = collect($data['markets'])
            ->filter(fn (array $row): bool => $row['attributes']['state'] !== 'Selangor')
            ->pluck('_row')
            ->all();

        if ($nonSelangorRows !== []) {
            $report['errors'][] = 'Production NightMarkets rows must all be in Selangor; rejected normalized rows: '
                .implode(', ', $nonSelangorRows).'.';
        }

        $nonUnknownHalalRows = collect($data['stalls'])
            ->filter(fn (array $row): bool => $row['attributes']['halal_status'] !== Stall::HALAL_UNKNOWN)
            ->pluck('_row')
            ->all();

        if ($nonUnknownHalalRows !== []) {
            $report['errors'][] = 'Production Stalls must preserve the reviewed Unknown Halal classification; rejected normalized rows: '
                .implode(', ', $nonUnknownHalalRows).'.';
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>  $report
     * @return array<string, true>
     */
    private function uniqueCodes(array $rows, string $field, string $sheet, array &$report): array
    {
        $seen = [];
        foreach ($rows as $row) {
            $code = $this->requiredCode($row[$field], $sheet, $row['_row'], $field, $report);
            if ($code === null) {
                continue;
            }
            $lookupCode = Str::lower($code);
            if (isset($seen[$lookupCode])) {
                $this->error($report, $sheet, $row['_row'], "duplicate {$field} {$code}; first seen on row {$seen[$lookupCode]['row']}");
            } else {
                $seen[$lookupCode] = ['code' => $code, 'row' => $row['_row']];
            }
        }

        return collect($seen)->mapWithKeys(fn (array $item) => [$item['code'] => true])->all();
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $data
     * @param  array<string, mixed>  $report
     * @return array<string, array<string, Model|null>>
     */
    private function buildPlan(array $data, array &$report): array
    {
        $plan = ['markets' => [], 'stalls' => [], 'foods' => []];
        $existingMarkets = NightMarket::whereIn('catalog_code', collect($data['markets'])->pluck('code'))
            ->get()
            ->keyBy(fn (NightMarket $market) => Str::lower($market->catalog_code));
        $untaggedMarkets = NightMarket::whereNull('catalog_code')->get(['id', 'name', 'address']);

        foreach ($data['markets'] as $row) {
            $model = $existingMarkets->get(Str::lower($row['code']));
            if ($model === null) {
                $collisions = $untaggedMarkets->filter(fn (NightMarket $candidate) => $this->identity($candidate->name) === $this->identity($row['attributes']['name']) && $this->identity($candidate->address) === $this->identity($row['attributes']['address']));
                if ($collisions->isNotEmpty()) {
                    $this->collision($report, 'NightMarkets', $row['_row'], $row['code']);
                    $plan['markets'][$row['code']] = null;

                    continue;
                }
            }
            $plan['markets'][$row['code']] = $model;
            $this->classify($report, 'markets', $model, $row['attributes']);
        }

        foreach ($data['schedules'] as $row) {
            $market = $plan['markets'][$row['market_code']] ?? null;
            if (array_key_exists($row['market_code'], $plan['markets']) && $market === null && $existingMarkets->has(Str::lower($row['market_code'])) === false) {
                $report['counts']['schedules']['created']++;

                continue;
            }
            if ($market === null) {
                $report['counts']['schedules']['skipped']++;

                continue;
            }
            $schedule = MarketOperatingDay::where('night_market_id', $market->id)->where('day_of_week', $row['day'])->first();
            $attributes = ['opening_time' => $row['opening_time'].':00', 'closing_time' => $row['closing_time'].':00'];
            $this->classify($report, 'schedules', $schedule, $attributes);
        }

        $existingStalls = Stall::whereIn('catalog_code', collect($data['stalls'])->pluck('code'))
            ->get()
            ->keyBy(fn (Stall $stall) => Str::lower($stall->catalog_code));
        foreach ($data['stalls'] as $row) {
            $model = $existingStalls->get(Str::lower($row['code']));
            $parent = $plan['markets'][$row['market_code']] ?? null;
            if ($model === null && $parent !== null) {
                $collision = Stall::whereNull('catalog_code')->where('night_market_id', $parent->id)->get(['id', 'name'])->contains(fn (Stall $candidate) => $this->identity($candidate->name) === $this->identity($row['attributes']['name']));
                if ($collision) {
                    $this->collision($report, 'Stalls', $row['_row'], $row['code']);
                    $plan['stalls'][$row['code']] = null;

                    continue;
                }
            }
            $plan['stalls'][$row['code']] = $model;
            $attributes = $row['attributes'];
            if ($parent !== null) {
                $attributes['night_market_id'] = $parent->id;
            }
            $this->classify($report, 'stalls', $model, $attributes);
        }

        $existingFoods = Food::whereIn('catalog_code', collect($data['foods'])->pluck('code'))
            ->get()
            ->keyBy(fn (Food $food) => Str::lower($food->catalog_code));
        foreach ($data['foods'] as $row) {
            $model = $existingFoods->get(Str::lower($row['code']));
            $parent = $plan['stalls'][$row['stall_code']] ?? null;
            if ($model === null && $parent !== null) {
                $collision = Food::whereNull('catalog_code')->where('stall_id', $parent->id)->get(['id', 'name'])->contains(fn (Food $candidate) => $this->identity($candidate->name) === $this->identity($row['attributes']['name']));
                if ($collision) {
                    $this->collision($report, 'Foods', $row['_row'], $row['code']);
                    $plan['foods'][$row['code']] = null;

                    continue;
                }
            }
            $plan['foods'][$row['code']] = $model;
            $attributes = $row['attributes'];
            if ($parent !== null) {
                $attributes['stall_id'] = $parent->id;
            }
            $this->classify($report, 'foods', $model, $attributes);
        }

        return $plan;
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $data
     * @param  array<string, array<string, Model|null>>  $plan
     */
    private function applyPlan(array $data, array $plan): void
    {
        $markets = [];
        foreach ($data['markets'] as $row) {
            $model = $plan['markets'][$row['code']] ?? new NightMarket;
            $model->forceFill(['catalog_code' => $row['code'], ...$row['attributes']]);
            $model->saveOrFail();
            $markets[$row['code']] = $model;
        }

        foreach ($data['schedules'] as $row) {
            $schedule = MarketOperatingDay::where('night_market_id', $markets[$row['market_code']]->id)
                ->where('day_of_week', $row['day'])
                ->first() ?? new MarketOperatingDay;
            $schedule->forceFill([
                'night_market_id' => $markets[$row['market_code']]->id,
                'day_of_week' => $row['day'],
                'opening_time' => $row['opening_time'],
                'closing_time' => $row['closing_time'],
            ]);
            $schedule->saveOrFail();
        }

        $stalls = [];
        foreach ($data['stalls'] as $row) {
            $model = $plan['stalls'][$row['code']] ?? new Stall;
            $model->forceFill(['catalog_code' => $row['code'], 'night_market_id' => $markets[$row['market_code']]->id, ...$row['attributes']]);
            $model->saveOrFail();
            $stalls[$row['code']] = $model;
        }

        foreach ($data['foods'] as $row) {
            $model = $plan['foods'][$row['code']] ?? new Food;
            $model->forceFill(['catalog_code' => $row['code'], 'stall_id' => $stalls[$row['stall_code']]->id, ...$row['attributes']]);
            $model->saveOrFail();
        }
    }

    /** @param array<string, mixed> $report */
    private function collision(array &$report, string $sheet, int $row, string $code): void
    {
        $this->error($report, $sheet, $row, "catalog code {$code} collides with an untagged existing record; no record was adopted or created");
    }

    /** @param array<string, mixed> $report @param array<string, mixed> $attributes */
    private function classify(array &$report, string $entity, ?Model $model, array $attributes): void
    {
        if ($model === null) {
            $report['counts'][$entity]['created']++;
        } elseif ($this->attributesDiffer($model, $attributes)) {
            $report['counts'][$entity]['updated']++;
        } else {
            $report['counts'][$entity]['unchanged']++;
        }
    }

    /** @param array<string, mixed> $attributes */
    private function attributesDiffer(Model $model, array $attributes): bool
    {
        $copy = clone $model;
        $copy->forceFill($attributes);

        return $copy->isDirty(array_keys($attributes));
    }

    private function identity(?string $value): string
    {
        return Str::lower(Str::squish((string) $value));
    }

    /** @param array<string, mixed> $report */
    private function requiredName(mixed $value, string $sheet, int $row, string $field, array &$report): void
    {
        $name = $this->string($value);
        $placeholders = ['', 'not stated', 'n/a', 'na', 'none', 'unknown', 'tbd', 'placeholder'];
        if (in_array(Str::lower($name), $placeholders, true)) {
            $this->error($report, $sheet, $row, "{$field} must be a real entity name");
        } elseif (mb_strlen($name) > 255) {
            $this->error($report, $sheet, $row, "{$field} exceeds 255 characters");
        }
    }

    /** @param array<string, mixed> $report */
    private function requiredString(mixed $value, int $max, string $sheet, int $row, string $field, array &$report): void
    {
        $value = $this->string($value);
        if ($value === '') {
            $this->error($report, $sheet, $row, "{$field} is required");
        } elseif (mb_strlen($value) > $max) {
            $this->error($report, $sheet, $row, "{$field} exceeds {$max} characters");
        }
    }

    /** @param array<string, mixed> $report */
    private function nullableStringLimit(mixed $value, int $max, string $sheet, int $row, string $field, array &$report): void
    {
        if ($this->nullableString($value) !== null && mb_strlen($this->string($value)) > $max) {
            $this->error($report, $sheet, $row, "{$field} exceeds {$max} characters");
        }
    }

    /** @param array<string, mixed> $report */
    private function nullableText(mixed $value, string $sheet, int $row, string $field, array &$report): void
    {
        if ($this->nullableString($value) !== null && strlen($this->string($value)) > 65535) {
            $this->error($report, $sheet, $row, "{$field} exceeds the database text limit");
        }
    }

    /** @param array<string, mixed> $report */
    private function requiredCode(mixed $value, string $sheet, int $row, string $field, array &$report): ?string
    {
        $code = $this->string($value);
        if ($code === '') {
            $this->error($report, $sheet, $row, "{$field} is required");

            return null;
        }
        if (mb_strlen($code) > 64) {
            $this->error($report, $sheet, $row, "{$field} exceeds 64 characters");

            return null;
        }

        return $code;
    }

    /** @param array<string, mixed> $report */
    private function status(mixed $value, string $sheet, int $row, array &$report): ?string
    {
        $status = Str::lower($this->string($value));
        if (! in_array($status, [NightMarket::STATUS_ACTIVE, NightMarket::STATUS_INACTIVE], true)) {
            $this->error($report, $sheet, $row, 'status must be Active or Inactive');

            return null;
        }

        return $status;
    }

    /** @param array<string, mixed> $report */
    private function halalStatus(mixed $value, string $sheet, int $row, array &$report): ?string
    {
        $key = Str::lower($this->string($value));
        $map = [
            'halal-certified' => Stall::HALAL_CERTIFIED,
            'halal_certified' => Stall::HALAL_CERTIFIED,
            'muslim-owned/claimed' => Stall::HALAL_MUSLIM_OWNED_OR_CLAIMED,
            'muslim_owned_or_claimed' => Stall::HALAL_MUSLIM_OWNED_OR_CLAIMED,
            'non-halal' => Stall::HALAL_NON_HALAL,
            'non_halal' => Stall::HALAL_NON_HALAL,
            'unknown' => Stall::HALAL_UNKNOWN,
        ];
        if (! isset($map[$key])) {
            $this->error($report, $sheet, $row, 'halal_status is not an approved Stall classification');

            return null;
        }

        return $map[$key];
    }

    /** @param array<string, mixed> $report */
    private function mustTry(mixed $value, string $sheet, int $row, array &$report): ?bool
    {
        $value = Str::lower($this->string($value));
        if ($value === 'yes') {
            return true;
        }
        if ($value === 'no') {
            return false;
        }

        $this->error($report, $sheet, $row, 'must_try must be Yes or No');

        return null;
    }

    /** @param array<string, mixed> $report */
    private function url(mixed $value, string $sheet, int $row, string $field, array &$report): ?string
    {
        $url = $this->nullableString($value);
        if ($url === null) {
            return null;
        }
        if (mb_strlen($url) > 255 || ! filter_var($url, FILTER_VALIDATE_URL) || ! in_array(Str::lower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)) {
            $this->error($report, $sheet, $row, "{$field} must be a valid HTTP/HTTPS URL of at most 255 characters");

            return null;
        }

        return $url;
    }

    /** @param array<string, mixed> $report */
    private function date(mixed $value, string $sheet, int $row, string $field, array &$report): ?string
    {
        $date = $this->nullableString($value);
        if ($date === null) {
            return null;
        }
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            $this->error($report, $sheet, $row, "{$field} must use YYYY-MM-DD");

            return null;
        }

        return $date;
    }

    /** @param array<string, mixed> $report */
    private function time(mixed $value, string $sheet, int $row, string $field, array &$report): ?string
    {
        $time = $this->string($value);
        $parsed = \DateTimeImmutable::createFromFormat('!H:i', $time);
        if ($parsed === false || $parsed->format('H:i') !== $time) {
            $this->error($report, $sheet, $row, "{$field} must use HH:MM");

            return null;
        }

        return $time;
    }

    /** @param array<string, mixed> $report */
    private function price(mixed $value, string $sheet, int $row, string $field, array &$report): ?string
    {
        $price = $this->nullableString($value);
        if ($price === null) {
            return null;
        }
        if (preg_match('/\A\d{1,8}(?:\.\d{1,2})?\z/', $price) !== 1 || (float) $price < 0) {
            $this->error($report, $sheet, $row, "{$field} must be a non-negative decimal that fits decimal(10,2)");

            return null;
        }

        return number_format((float) $price, 2, '.', '');
    }

    /** @param array<string, mixed> $report */
    private function coordinates(mixed $latitude, mixed $longitude, string $sheet, int $row, array &$report): void
    {
        foreach ([['latitude', $latitude, -90, 90], ['longitude', $longitude, -180, 180]] as [$field, $value, $minimum, $maximum]) {
            $coordinate = $this->nullableString($value);
            if ($coordinate !== null && (! is_numeric($coordinate) || (float) $coordinate < $minimum || (float) $coordinate > $maximum)) {
                $this->error($report, $sheet, $row, "{$field} is outside its valid range");
            }
        }
    }

    private function string(mixed $value): string
    {
        return trim(is_scalar($value) ? (string) $value : '');
    }

    private function nullableString(mixed $value): ?string
    {
        $value = $this->string($value);

        return $value === '' ? null : $value;
    }

    /** @param array<string, mixed> $report */
    private function error(array &$report, string $sheet, int $row, string $message): void
    {
        $entity = match ($sheet) {
            'NightMarkets' => 'markets',
            'MarketSchedules' => 'schedules',
            'Stalls' => 'stalls',
            'Foods' => 'foods',
            default => null,
        };
        $rowKey = "{$sheet}:{$row}";
        if ($entity !== null && ! isset($report['rejected_rows'][$rowKey])) {
            $report['counts'][$entity]['rejected']++;
            $report['rejected_rows'][$rowKey] = true;
        }
        $report['errors'][] = "{$sheet} row {$row}: {$message}.";
    }

    /** @return array<string, mixed> */
    private function emptyReport(bool $apply): array
    {
        $blank = ['validated' => 0, 'created' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => 0, 'rejected' => 0];

        return [
            'mode' => $apply ? 'apply' : 'dry-run',
            'applied' => false,
            'errors' => [],
            'rejected_rows' => [],
            'records' => [],
            'counts' => ['markets' => $blank, 'schedules' => $blank, 'stalls' => $blank, 'foods' => $blank],
            'unmapped' => self::UNMAPPED,
        ];
    }
}
