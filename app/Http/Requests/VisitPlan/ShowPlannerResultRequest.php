<?php

namespace App\Http\Requests\VisitPlan;

class ShowPlannerResultRequest extends PlannerSnapshotRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['snapshot_id' => $this->route('snapshot')]);
    }
}
