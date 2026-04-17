<?php

declare(strict_types=1);

namespace App\Livewire\SuperAdmin;

use App\Models\Association;
use App\Models\SuperAdminAccessLog;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

final class AssociationDetail extends Component
{
    public Association $association;

    public string $tab = 'info';

    public function mount(Association $association): void
    {
        $this->association = $association;
    }

    public function suspend(): void
    {
        abort_unless(auth()->user()?->isSuperAdmin(), 403);
        if ($this->association->statut !== 'actif') {
            $this->addError('statut', "Transition impossible depuis '{$this->association->statut}'.");
            return;
        }

        DB::transaction(function () {
            $this->association->update(['statut' => 'suspendu']);
            $this->logTransition('suspend');
        });
    }

    public function reactivate(): void
    {
        abort_unless(auth()->user()?->isSuperAdmin(), 403);
        if ($this->association->statut !== 'suspendu') {
            $this->addError('statut', "Transition impossible depuis '{$this->association->statut}'.");
            return;
        }

        DB::transaction(function () {
            $this->association->update(['statut' => 'actif']);
            $this->logTransition('reactivate');
        });
    }

    public function archive(): void
    {
        abort_unless(auth()->user()?->isSuperAdmin(), 403);
        if ($this->association->statut !== 'suspendu') {
            $this->addError('statut', "Seule une asso suspendue peut être archivée.");
            return;
        }

        DB::transaction(function () {
            $this->association->update(['statut' => 'archive']);
            $this->logTransition('archive');
        });
    }

    private function logTransition(string $action): void
    {
        SuperAdminAccessLog::create([
            'user_id' => auth()->id(),
            'association_id' => $this->association->id,
            'action' => $action,
            'payload' => ['new_statut' => $this->association->statut],
            'created_at' => now(),
        ]);
    }

    public function render(): View
    {
        $users = $this->association->users()
            ->withPivot(['role', 'joined_at', 'revoked_at'])
            ->orderByPivot('joined_at', 'desc')
            ->get();

        $logs = SuperAdminAccessLog::query()
            ->where('association_id', $this->association->id)
            ->with('user')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        return view('livewire.super-admin.association-detail', [
            'association' => $this->association,
            'users' => $users,
            'logs' => $logs,
        ]);
    }
}
