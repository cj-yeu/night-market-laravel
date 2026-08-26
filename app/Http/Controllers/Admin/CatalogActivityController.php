<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CatalogActivityFilterRequest;
use App\Services\CatalogAuditLogService;
use Illuminate\View\View;

class CatalogActivityController extends Controller
{
    public function __construct(private readonly CatalogAuditLogService $catalogAuditLogService) {}

    public function index(CatalogActivityFilterRequest $request): View
    {
        return view('admin.catalog-activity.index', [
            'logs' => $this->catalogAuditLogService->paginate($request->validated()),
            'filters' => $request->validated(),
        ]);
    }
}
