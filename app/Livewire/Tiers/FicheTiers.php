<?php

declare(strict_types=1);

namespace App\Livewire\Tiers;

use App\Enums\TypeTransaction;
use App\Models\Participant;
use App\Models\Tiers;
use App\Models\TransactionLigne;
use App\Services\Tiers\TiersAdhesionTimelineService;
use App\Services\Tiers\TiersCommunicationsTimelineService;
use App\Services\Tiers\TiersDocumentsTimelineService;
use App\Services\Tiers\TiersDonsTimelineService;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

final class FicheTiers extends Component
{
    public Tiers $tiers;

    #[Url(as: 'onglet')]
    public ?string $onglet = null;

    public function mount(Tiers $tiers): void
    {
        $this->tiers = $tiers;
    }

    public function render(): View
    {
        $donsCount = app(TiersDonsTimelineService::class)
            ->forTiers($this->tiers)
            ->totalCount;

        $onglets = [
            ['key' => 'coordonnees', 'label' => 'Coordonnées', 'count' => null],
        ];

        if ($donsCount > 0) {
            $onglets[] = ['key' => 'dons', 'label' => 'Dons', 'count' => $donsCount];
        }

        $adhesionsCount = app(TiersAdhesionTimelineService::class)
            ->forTiers($this->tiers)
            ->totalCount;

        if ($adhesionsCount > 0) {
            $onglets[] = ['key' => 'adhesion', 'label' => 'Adhésion', 'count' => $adhesionsCount];
        }

        $nbParticipations = $this->tiers->participants()->count();
        $nbReferre = Participant::where('refere_par_id', $this->tiers->id)
            ->distinct()->count('tiers_id');
        $nbSuit = Participant::where(fn ($q) => $q
            ->where('medecin_tiers_id', $this->tiers->id)
            ->orWhere('therapeute_tiers_id', $this->tiers->id)
        )->distinct()->count('tiers_id');
        $nbEnc = TransactionLigne::query()
            ->join('transactions', 'transactions.id', '=', 'transaction_lignes.transaction_id')
            ->where('transactions.tiers_id', $this->tiers->id)
            ->where('transactions.type', TypeTransaction::Depense->value)
            ->whereNotNull('transaction_lignes.operation_id')
            ->distinct()
            ->count('transaction_lignes.operation_id');
        $totalOperations = $nbParticipations + $nbReferre + $nbSuit + $nbEnc;
        if ($totalOperations > 0) {
            $onglets[] = ['key' => 'operations', 'label' => 'Opérations', 'count' => $totalOperations];
        }

        $nbCommunications = app(TiersCommunicationsTimelineService::class)->countTotal($this->tiers);
        if ($nbCommunications > 0) {
            $onglets[] = ['key' => 'communications', 'label' => 'Communications', 'count' => $nbCommunications];
        }

        $nbDocuments = app(TiersDocumentsTimelineService::class)->countTotal($this->tiers);
        if ($nbDocuments > 0) {
            $onglets[] = ['key' => 'documents', 'label' => 'Documents', 'count' => $nbDocuments];
        }

        $current = in_array($this->onglet, array_column($onglets, 'key'), true)
            ? $this->onglet
            : 'coordonnees';

        return view('livewire.tiers.fiche-tiers', [
            'onglets' => $onglets,
            'currentOnglet' => $current,
        ]);
    }
}
