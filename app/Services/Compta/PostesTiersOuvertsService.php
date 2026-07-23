<?php

declare(strict_types=1);

namespace App\Services\Compta;

use App\DTOs\Compta\PosteTiersOuvert;
use App\DTOs\Compta\ReglementPosteTiers;
use App\Enums\ModePaiement;
use App\Models\ANouveauLigneOrigine;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Services\Compta\ANouveau\PosteReporteResolver;
use App\Services\ExerciceService;
use App\Tenant\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class PostesTiersOuvertsService
{
    public function __construct(
        private readonly ExerciceService $exerciceService,
        private readonly PosteReporteResolver $posteReporteResolver,
    ) {}

    /**
     * @return Collection<int, PosteTiersOuvert>
     */
    public function chercher(
        int $exercice,
        ?string $compte,
        ?int $tiersId,
        ?int $exerciceOrigine,
        ?string $recherche,
    ): Collection {
        return $this->projection($exercice)
            ->when(
                $compte !== null,
                fn (Collection $postes): Collection => $postes
                    ->where('numeroCompte', $compte)
                    ->values(),
            )
            ->when(
                $tiersId !== null,
                fn (Collection $postes): Collection => $postes
                    ->where('tiersId', $tiersId)
                    ->values(),
            )
            ->when(
                $exerciceOrigine !== null,
                fn (Collection $postes): Collection => $postes
                    ->where('exerciceOrigine', $exerciceOrigine)
                    ->values(),
            )
            ->when(
                $recherche !== null && trim($recherche) !== '',
                function (Collection $postes) use ($recherche): Collection {
                    $terme = mb_strtolower(trim((string) $recherche));

                    return $postes
                        ->filter(function (PosteTiersOuvert $poste) use ($terme): bool {
                            $champs = [
                                $poste->tiersNom,
                                $poste->numeroPiece,
                                $poste->reference,
                                $poste->libelle,
                            ];

                            return collect($champs)
                                ->filter(fn (?string $champ): bool => $champ !== null)
                                ->contains(
                                    fn (string $champ): bool => str_contains(mb_strtolower($champ), $terme)
                                );
                        })
                        ->values();
                },
            )
            ->sortBy(fn (PosteTiersOuvert $poste): array => [
                $poste->dateOrigine->toDateString(),
                $poste->numeroPiece ?? '',
                $poste->ligneCanoniqueId,
            ])
            ->values();
    }

    /**
     * @return LengthAwarePaginator<int, PosteTiersOuvert>
     */
    public function paginer(
        int $exercice,
        ?string $compte,
        ?int $tiersId,
        ?int $exerciceOrigine,
        ?string $recherche,
        int $parPage,
        int $page,
    ): LengthAwarePaginator {
        $parPage = max(1, $parPage);
        $page = max(1, $page);
        $postes = $this->chercher($exercice, $compte, $tiersId, $exerciceOrigine, $recherche);

        return new LengthAwarePaginator(
            items: $postes->forPage($page, $parPage)->values(),
            total: $postes->count(),
            perPage: $parPage,
            currentPage: $page,
            options: [
                'path' => LengthAwarePaginator::resolveCurrentPath(),
                'pageName' => 'page',
            ],
        );
    }

    public function trouver(int $ligneId, int $exercice): PosteTiersOuvert
    {
        $poste = $this->chercher($exercice, null, null, null, null)
            ->first(fn (PosteTiersOuvert $poste): bool => $poste->ligneCanoniqueId === $ligneId
                || in_array($ligneId, $poste->ligneIdsOuvertes, true));

        if ($poste === null) {
            throw (new ModelNotFoundException)->setModel(TransactionLigne::class, [$ligneId]);
        }

        return $poste;
    }

    public function pourTransaction(Transaction $transaction, int $exercice): ?PosteTiersOuvert
    {
        if (TenantContext::currentId() === null
            || (int) $transaction->association_id !== (int) TenantContext::currentId()) {
            return null;
        }

        return $this->chercher($exercice, null, null, null, null)
            ->firstWhere('transactionOrigineId', (int) $transaction->id);
    }

    public function soldeActifPourTransaction(Transaction $transaction): int
    {
        if (TenantContext::currentId() === null
            || (int) $transaction->association_id !== (int) TenantContext::currentId()) {
            return 0;
        }

        $ligneActive = $this->posteReporteResolver->dernierePourTransaction($transaction);
        if ($ligneActive === null) {
            return 0;
        }

        $ligneCanoniqueId = $ligneActive->poste_tiers_parent_id ?? (int) $ligneActive->id;

        return TransactionLigne::query()
            ->with('compte')
            ->where('transaction_id', (int) $ligneActive->transaction_id)
            ->where(function (Builder $query) use ($ligneCanoniqueId): void {
                $query
                    ->whereKey($ligneCanoniqueId)
                    ->orWhere('poste_tiers_parent_id', $ligneCanoniqueId);
            })
            ->whereNull('lettrage_code')
            ->get()
            ->sum(fn (TransactionLigne $ligne): int => $this->soldeLigneCentimes($ligne));
    }

    /**
     * @return Collection<int, ReglementPosteTiers>
     */
    public function reglements(Transaction $transaction): Collection
    {
        if (TenantContext::currentId() === null
            || (int) $transaction->association_id !== (int) TenantContext::currentId()) {
            return collect();
        }

        $ligneRacine = $this->ligneTiers($transaction);
        if ($ligneRacine === null) {
            return collect();
        }

        $racineId = $this->posteReporteResolver->racineId($ligneRacine);
        $ligneCanoniques = ANouveauLigneOrigine::query()
            ->where('ligne_racine_id', $racineId)
            ->pluck('ligne_an_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->push($racineId)
            ->unique()
            ->values();

        $lignesPoste = TransactionLigne::query()
            ->where(function (Builder $query) use ($ligneCanoniques): void {
                $query
                    ->whereIn('id', $ligneCanoniques->all())
                    ->orWhereIn('poste_tiers_parent_id', $ligneCanoniques->all());
            })
            ->whereNotNull('lettrage_code')
            ->get();

        $codes = $lignesPoste
            ->pluck('lettrage_code')
            ->filter()
            ->unique()
            ->values();
        if ($codes->isEmpty()) {
            return collect();
        }

        $transactionsPoste = $lignesPoste
            ->pluck('transaction_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->all();

        return TransactionLigne::query()
            ->with(['compte', 'transaction'])
            ->whereIn('lettrage_code', $codes->all())
            ->whereNotIn('transaction_id', $transactionsPoste)
            ->whereHas(
                'compte',
                fn (Builder $query): Builder => $query->whereIn('numero_pcg', ['401', '411'])
            )
            ->get()
            ->filter(fn (TransactionLigne $ligne): bool => $ligne->transaction?->mode_paiement instanceof ModePaiement)
            ->unique(fn (TransactionLigne $ligne): int => (int) $ligne->transaction_id)
            ->map(function (TransactionLigne $ligne): ReglementPosteTiers {
                /** @var Transaction $transaction */
                $transaction = $ligne->transaction;
                /** @var ModePaiement $mode */
                $mode = $transaction->mode_paiement;

                return new ReglementPosteTiers(
                    transactionId: (int) $transaction->id,
                    montantCentimes: abs(
                        (int) round((float) $ligne->debit * 100)
                        - (int) round((float) $ligne->credit * 100)
                    ),
                    date: CarbonImmutable::parse($transaction->date->toDateString()),
                    mode: $mode,
                    annulable: $this->estReglementAnnulable($transaction),
                );
            })
            ->sortBy(fn (ReglementPosteTiers $reglement): array => [
                $reglement->date->toDateString(),
                $reglement->transactionId,
            ])
            ->values();
    }

    /**
     * @return Collection<int, PosteTiersOuvert>
     */
    private function projection(int $exercice): Collection
    {
        $associationId = TenantContext::currentId();
        if ($associationId === null) {
            return collect();
        }

        $range = $this->exerciceService->dateRange($exercice);
        $canoniqueSql = 'COALESCE(ouverte.poste_tiers_parent_id, ouverte.id)';
        $soldeSql = "SUM(CASE WHEN comptes.numero_pcg = '411'
            THEN ROUND(ouverte.debit * 100) - ROUND(ouverte.credit * 100)
            ELSE ROUND(ouverte.credit * 100) - ROUND(ouverte.debit * 100)
        END)";

        return DB::table('transaction_lignes as ouverte')
            ->join('transactions as active_tx', 'active_tx.id', '=', 'ouverte.transaction_id')
            ->join('comptes', function (JoinClause $join) use ($associationId): void {
                $join
                    ->on('comptes.id', '=', 'ouverte.compte_id')
                    ->where('comptes.association_id', $associationId)
                    ->whereNull('comptes.deleted_at');
            })
            ->join('tiers', function (JoinClause $join) use ($associationId): void {
                $join
                    ->on('tiers.id', '=', 'ouverte.tiers_id')
                    ->where('tiers.association_id', $associationId);
            })
            ->leftJoin('transaction_lignes as parent', 'parent.id', '=', 'ouverte.poste_tiers_parent_id')
            ->leftJoin('a_nouveau_ligne_origines as origine', function (JoinClause $join) use (
                $associationId,
                $canoniqueSql,
            ): void {
                $join
                    ->whereRaw("origine.ligne_an_id = {$canoniqueSql}")
                    ->where('origine.association_id', $associationId);
            })
            ->leftJoin('transaction_lignes as racine', 'racine.id', '=', 'origine.ligne_racine_id')
            ->leftJoin('transactions as racine_tx', function (JoinClause $join) use ($associationId): void {
                $join
                    ->on('racine_tx.id', '=', 'racine.transaction_id')
                    ->where('racine_tx.association_id', $associationId);
            })
            ->selectRaw("{$canoniqueSql} as ligne_canonique_id")
            ->selectRaw(
                "COALESCE(MAX(CASE WHEN ouverte.id = {$canoniqueSql} THEN ouverte.id END), MIN(ouverte.id))
                    as ligne_action_id"
            )
            ->selectRaw('GROUP_CONCAT(ouverte.id) as ligne_ids_ouvertes')
            ->selectRaw("{$soldeSql} as solde_centimes")
            ->selectRaw('comptes.id as compte_id, comptes.numero_pcg as numero_compte')
            ->selectRaw('ouverte.tiers_id as tiers_id')
            ->selectRaw('MIN(tiers.type) as tiers_type')
            ->selectRaw('MIN(tiers.nom) as tiers_nom')
            ->selectRaw('MIN(tiers.prenom) as tiers_prenom')
            ->selectRaw('MIN(tiers.entreprise) as tiers_entreprise')
            ->selectRaw('MIN(active_tx.id) as active_transaction_id')
            ->selectRaw('MIN(active_tx.date) as active_date')
            ->selectRaw('MIN(active_tx.numero_piece) as active_numero_piece')
            ->selectRaw('MIN(active_tx.reference) as active_reference')
            ->selectRaw('MIN(active_tx.libelle) as active_libelle')
            ->selectRaw('MAX(CASE WHEN origine.id IS NULL THEN 0 ELSE 1 END) as est_reporte')
            ->selectRaw('MIN(racine_tx.id) as racine_transaction_id')
            ->selectRaw('MIN(racine_tx.date) as racine_date')
            ->selectRaw('MIN(racine_tx.numero_piece) as racine_numero_piece')
            ->selectRaw('MIN(racine_tx.reference) as racine_reference')
            ->selectRaw('MIN(racine_tx.libelle) as racine_libelle')
            ->whereIn('comptes.numero_pcg', ['401', '411'])
            ->whereNotNull('ouverte.tiers_id')
            ->whereNull('ouverte.lettrage_code')
            ->whereNull('ouverte.deleted_at')
            ->whereNull('active_tx.deleted_at')
            ->where('active_tx.association_id', $associationId)
            ->whereBetween('active_tx.date', [
                $range['start']->toDateString(),
                $range['end']->toDateString(),
            ])
            ->groupBy(DB::raw($canoniqueSql), 'comptes.id', 'comptes.numero_pcg', 'ouverte.tiers_id')
            ->havingRaw("{$soldeSql} > 0")
            ->get()
            ->map(function (object $ligne) use ($exercice, $range): PosteTiersOuvert {
                $estReporte = (int) $ligne->est_reporte === 1;
                $dateOrigine = CarbonImmutable::parse(
                    $estReporte && $ligne->racine_date !== null
                        ? (string) $ligne->racine_date
                        : (string) $ligne->active_date
                );
                $ligneIds = collect(explode(',', (string) $ligne->ligne_ids_ouvertes))
                    ->map(fn (string $id): int => (int) $id)
                    ->sort()
                    ->values()
                    ->all();

                return new PosteTiersOuvert(
                    ligneActionId: (int) $ligne->ligne_action_id,
                    ligneCanoniqueId: (int) $ligne->ligne_canonique_id,
                    ligneIdsOuvertes: $ligneIds,
                    transactionOrigineId: (int) (
                        $estReporte && $ligne->racine_transaction_id !== null
                            ? $ligne->racine_transaction_id
                            : $ligne->active_transaction_id
                    ),
                    compteId: (int) $ligne->compte_id,
                    numeroCompte: (string) $ligne->numero_compte,
                    tiersId: (int) $ligne->tiers_id,
                    tiersNom: $this->nomTiers($ligne),
                    soldeCentimes: (int) round((float) $ligne->solde_centimes),
                    dateOrigine: $dateOrigine,
                    dateAffichage: $estReporte
                        ? CarbonImmutable::instance($range['start'])
                        : $dateOrigine,
                    numeroPiece: $this->champTransaction($ligne, 'numero_piece', $estReporte),
                    reference: $this->champTransaction($ligne, 'reference', $estReporte),
                    libelle: $this->champTransaction($ligne, 'libelle', $estReporte) ?? '',
                    exerciceOrigine: $this->exerciceService->anneeForDate($dateOrigine),
                    exerciceActif: $exercice,
                    estReporte: $estReporte,
                );
            })
            ->values();
    }

    private function ligneTiers(Transaction $transaction): ?TransactionLigne
    {
        return TransactionLigne::query()
            ->with('compte')
            ->where('transaction_id', (int) $transaction->id)
            ->whereNotNull('tiers_id')
            ->whereHas(
                'compte',
                fn (Builder $query): Builder => $query->whereIn('numero_pcg', ['401', '411'])
            )
            ->orderBy('id')
            ->first();
    }

    private function soldeLigneCentimes(TransactionLigne $ligne): int
    {
        if ($ligne->tiers_id === null || ! in_array($ligne->compte?->numero_pcg, ['401', '411'], true)) {
            return 0;
        }

        $debit = (int) round((float) $ligne->debit * 100);
        $credit = (int) round((float) $ligne->credit * 100);

        return $ligne->compte?->numero_pcg === '411'
            ? $debit - $credit
            : $credit - $debit;
    }

    private function estReglementAnnulable(Transaction $transaction): bool
    {
        if ($transaction->rapprochement_id !== null || $transaction->remise_id !== null) {
            return false;
        }

        return ! TransactionLigne::query()
            ->where('transaction_id', (int) $transaction->id)
            ->whereNotNull('lettrage_code')
            ->whereHas(
                'compte',
                fn (Builder $query): Builder => $query->whereIn('numero_pcg', ['5112', '530'])
            )
            ->exists();
    }

    private function nomTiers(object $ligne): string
    {
        if ($ligne->tiers_type === 'entreprise' && trim((string) $ligne->tiers_entreprise) !== '') {
            return (string) $ligne->tiers_entreprise;
        }

        return trim(implode(' ', array_filter([
            $ligne->tiers_prenom,
            $ligne->tiers_nom,
        ], fn (mixed $valeur): bool => $valeur !== null && trim((string) $valeur) !== '')));
    }

    private function champTransaction(object $ligne, string $champ, bool $estReporte): ?string
    {
        $valeur = $estReporte ? $ligne->{'racine_'.$champ} : $ligne->{'active_'.$champ};

        return $valeur === null ? null : (string) $valeur;
    }
}
