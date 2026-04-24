<?php

declare(strict_types=1);

namespace App\Livewire\Portail\HistoriqueDepenses;

use App\Enums\TypeTransaction;
use App\Http\Resources\Portail\TransactionDepensePubliqueResource;
use App\Livewire\Portail\Concerns\WithPortailTenant;
use App\Models\Association;
use App\Models\Transaction;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Component;
use Livewire\WithPagination;

final class Index extends Component
{
    use WithPagination, WithPortailTenant;

    public Association $association;

    public function mount(Association $association): void
    {
        $this->association = $association;
    }

    public function render(): View
    {
        $tiers = Auth::guard('tiers-portail')->user();

        $transactions = Transaction::query()
            ->where('tiers_id', (int) $tiers->id)
            ->where('type', TypeTransaction::Depense)
            ->whereDoesntHave('noteDeFrais')
            ->orderByDesc('date')
            ->paginate(50);

        $slug = $this->association->slug;

        $resources = $transactions->getCollection()->map(
            fn (Transaction $tx) => (new TransactionDepensePubliqueResource($tx))
                ->withAssociationSlug($slug)
                ->toArray(request())
        );

        return view('livewire.portail.historique-depenses.index', [
            'transactions' => $transactions,
            'resources' => $resources,
        ])->layout('portail.layouts.app');
    }
}
