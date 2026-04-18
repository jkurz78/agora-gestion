<?php

declare(strict_types=1);

namespace App\Livewire\SuperAdmin;

use App\Models\Association;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Livewire\WithPagination;

final class AssociationsList extends Component
{
    use WithPagination;

    protected string $paginationTheme = 'bootstrap';

    public string $search = '';

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        $associations = Association::query()
            ->withCount(['users as active_users_count' => function ($q): void {
                $q->whereNull('association_user.revoked_at');
            }])
            ->when($this->search !== '', function ($q): void {
                $q->where(function ($qq): void {
                    $qq->where('nom', 'like', '%'.$this->search.'%')
                        ->orWhere('slug', 'like', '%'.$this->search.'%');
                });
            })
            ->orderBy('nom')
            ->paginate(25);

        return view('livewire.super-admin.associations-list', compact('associations'));
    }
}
