<?php

declare(strict_types=1);

namespace App\Livewire\SuperAdmin;

use App\Models\Association;
use App\Models\SuperAdminAccessLog;
use Illuminate\Contracts\View\View;
use Livewire\Component;

final class AssociationDetail extends Component
{
    public Association $association;

    public string $tab = 'info';

    public function mount(Association $association): void
    {
        $this->association = $association;
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
