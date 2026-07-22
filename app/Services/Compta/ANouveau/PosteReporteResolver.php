<?php

declare(strict_types=1);

namespace App\Services\Compta\ANouveau;

use App\Enums\StatutANouveau;
use App\Models\ANouveauLigneOrigine;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Services\ExerciceService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

final class PosteReporteResolver
{
    public function __construct(private readonly ExerciceService $exerciceService) {}

    public function pourTransaction(Transaction $transaction, CarbonInterface $date): ?TransactionLigne
    {
        $ligne = $this->ligneTiers($transaction);

        return $ligne === null ? null : $this->depuisLigne($ligne, $date);
    }

    public function depuisLigne(TransactionLigne $ligne, CarbonInterface $date): TransactionLigne
    {
        $exerciceCible = $this->exerciceService->anneeForDate(CarbonImmutable::instance($date));
        $racineId = $this->racineId($ligne);

        $origine = ANouveauLigneOrigine::query()
            ->with('ligneAN')
            ->where('ligne_racine_id', $racineId)
            ->whereHas('generation', function (Builder $query) use ($exerciceCible): void {
                $query
                    ->where('exercice_cible', $exerciceCible)
                    ->where('statut', StatutANouveau::Active);
            })
            ->latest('generation_id')
            ->first();

        return $origine?->ligneAN ?? $ligne;
    }

    public function dernierePourTransaction(
        Transaction $transaction,
        bool $exigerTiers = true,
    ): ?TransactionLigne {
        $ligne = $this->ligneTiers($transaction, $exigerTiers);
        if ($ligne === null) {
            return null;
        }

        $origine = ANouveauLigneOrigine::query()
            ->with('ligneAN')
            ->where('ligne_racine_id', $this->racineId($ligne))
            ->whereHas('generation', fn (Builder $query) => $query->where('statut', StatutANouveau::Active))
            ->latest('generation_id')
            ->first();

        return $origine?->ligneAN ?? $ligne;
    }

    private function ligneTiers(Transaction $transaction, bool $exigerTiers = true): ?TransactionLigne
    {
        $query = TransactionLigne::query()
            ->with('compte')
            ->where('transaction_id', (int) $transaction->id)
            ->whereHas('compte', fn (Builder $query) => $query->whereIn('numero_pcg', ['401', '411']))
            ->orderBy('id');

        if ($exigerTiers) {
            $query->whereNotNull('tiers_id');
        }

        return $query->first();
    }

    private function racineId(TransactionLigne $ligne): int
    {
        $origine = ANouveauLigneOrigine::query()
            ->where('ligne_an_id', (int) $ligne->id)
            ->latest('generation_id')
            ->first();

        return $origine?->ligne_racine_id ?? (int) $ligne->id;
    }
}
