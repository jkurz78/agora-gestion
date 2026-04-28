<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\DemoOperationBlockedException;
use App\Models\Association;
use App\Models\SuperAdminAccessLog;
use App\Support\Demo;
use Illuminate\Support\Facades\DB;

final class AssociationService
{
    public function suspend(Association $association): void
    {
        if (Demo::isActive()) {
            throw new DemoOperationBlockedException('suspension');
        }

        DB::transaction(function () use ($association) {
            $association->update(['statut' => 'suspendu']);
            $this->logTransition($association, 'suspend');
        });
    }

    public function archive(Association $association): void
    {
        if (Demo::isActive()) {
            throw new DemoOperationBlockedException('archivage');
        }

        DB::transaction(function () use ($association) {
            $association->update(['statut' => 'archive']);
            $this->logTransition($association, 'archive');
        });
    }

    public function reactivate(Association $association): void
    {
        DB::transaction(function () use ($association) {
            $association->update(['statut' => 'actif']);
            $this->logTransition($association, 'reactivate');
        });
    }

    private function logTransition(Association $association, string $action, ?array $payload = null): void
    {
        SuperAdminAccessLog::create([
            'user_id' => auth()->id(),
            'association_id' => $association->id,
            'action' => $action,
            'payload' => $payload ?? ['new_statut' => $association->statut],
            'created_at' => now(),
        ]);
    }
}
