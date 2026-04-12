<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\TypeOperation;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Livewire\Component;

final class TypeOperationList extends Component
{
    public string $filter = 'tous';

    public string $flashMessage = '';

    public string $flashType = '';

    public function delete(int $id): void
    {
        $type = TypeOperation::withCount('operations')->findOrFail($id);

        if ($type->operations_count > 0) {
            $this->flashMessage = 'Impossible de supprimer : des opérations utilisent ce type.';
            $this->flashType = 'danger';

            return;
        }

        if ($type->logo_path) {
            Storage::disk('public')->delete($type->logo_path);
        }

        $type->delete();
    }

    public function render(): View
    {
        $query = TypeOperation::with(['sousCategorie', 'tarifs'])
            ->withCount('operations');

        if ($this->filter === 'actif') {
            $query->where('actif', true);
        } elseif ($this->filter === 'inactif') {
            $query->where('actif', false);
        }

        $types = $query->orderBy('nom')->get();

        return view('livewire.type-operation-list', [
            'types' => $types,
        ]);
    }
}
