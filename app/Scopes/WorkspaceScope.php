<?php

namespace App\Scopes;

use App\Support\CurrentWorkspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class WorkspaceScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $workspaceId = app(CurrentWorkspace::class)->id();
        if ($workspaceId === null) {
            return;
        }

        $builder->where($model->getTable().'.workspace_id', $workspaceId);
    }
}
