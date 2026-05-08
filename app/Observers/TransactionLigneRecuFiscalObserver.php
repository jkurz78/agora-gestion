<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\RecuFiscalEmis;
use App\Models\TransactionLigne;
use App\Services\RecuFiscalService;

final class TransactionLigneRecuFiscalObserver
{
    public function __construct(
        private readonly RecuFiscalService $service,
    ) {}

    public function deleting(TransactionLigne $ligne): void
    {
        $this->annulerRecusActifs($ligne, 'Don supprimé');
    }

    public function updating(TransactionLigne $ligne): void
    {
        $champsCritiques = ['montant', 'sous_categorie_id'];
        $changements = array_intersect($champsCritiques, array_keys($ligne->getDirty()));

        if (empty($changements)) {
            return;
        }

        $detail = implode(', ', $changements);
        $this->annulerRecusActifs($ligne, "Don modifié — {$detail}");
    }

    private function annulerRecusActifs(TransactionLigne $ligne, string $motif): void
    {
        $recus = RecuFiscalEmis::query()
            ->where('transaction_ligne_id', $ligne->id)
            ->whereNull('annule_at')
            ->get();

        foreach ($recus as $recu) {
            $this->service->annuler($recu, $motif);
        }
    }
}
