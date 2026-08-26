<?php

namespace App\Support;

use App\Models\Workspace;
use App\Scopes\WorkspaceScope;

class CurrentWorkspace
{
    private ?string $id = null;

    private ?Workspace $workspace = null;

    public function set(Workspace|string|null $workspace): void
    {
        if ($workspace === null) {
            $this->id = null;
            $this->workspace = null;

            return;
        }

        if ($workspace instanceof Workspace) {
            $this->id = $workspace->getKey();
            $this->workspace = $workspace;

            return;
        }

        $this->id = $workspace;
        $this->workspace = null;
    }

    public function id(): ?string
    {
        return $this->id;
    }

    public function get(): ?Workspace
    {
        if ($this->workspace !== null) {
            return $this->workspace;
        }

        if ($this->id === null) {
            return null;
        }

        return $this->workspace = Workspace::query()
            ->withoutGlobalScope(WorkspaceScope::class)
            ->find($this->id);
    }

    public function clear(): void
    {
        $this->id = null;
        $this->workspace = null;
    }
}
