<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\CatalogDataQualityService;
use Illuminate\View\View;

class CatalogDataQualityController extends Controller
{
    public function __construct(private readonly CatalogDataQualityService $catalogDataQualityService) {}

    public function index(): View
    {
        return view('admin.catalog-data-quality.index', ['issues' => $this->catalogDataQualityService->summary()]);
    }

    public function records(string $issue): View
    {
        $definition = $this->catalogDataQualityService->issue($issue);

        return view('admin.catalog-data-quality.records', [
            'issue' => $issue,
            'definition' => $definition,
            'records' => $this->catalogDataQualityService->records($issue),
        ]);
    }
}
