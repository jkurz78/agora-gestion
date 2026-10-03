<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\TypeTransaction;
use App\Enums\UsageComptable;
use App\Models\Adhesion;
use App\Models\FormuleAdhesion;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Observers\AdhesionTransactionLigneObserver;
use App\Services\Adhesion\CompteFormuleResolver;
use App\Services\Adhesion\NouvelleAdhesionDTO;
use App\Tenant\TenantContext;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class AdhesionService
{
    public function __construct(
        private readonly ExerciceService $exerciceService,
        private readonly CompteFormuleResolver $formuleResolver,
        private readonly TransactionService $transactionService,
    ) {}

    public function creerDepuisTransaction(Transaction $tx): ?Adhesion
    {
        if ($tx->type !== TypeTransaction::Recette) {
            return null;
        }

        if (empty($tx->tiers_id)) {
            return null;
        }

        // DC-10a : la détection cotisation lit l'usage porté par le compte de la
        // ligne (compte_id, source unique) — plus de traversée comptes.
        // Prédicat ligne parente compatible manuel + HelloAsso (709A, T5) : une
        // ligne manuelle n'a pas de helloasso_item_id ; une ligne HelloAsso porte
        // toujours helloasso_line_key, et seule 'parent' désigne la ligne cotisation
        // (exclut 'discount' et 'option:{id}', qui partagent option_id NULL avec elle).
        $ligneCotisation = $tx->lignes()
            ->ligneParenteOuManuelle()
            ->whereHas('compte.usages', function ($q): void {
                $q->where('usage', UsageComptable::Cotisation->value);
            })
            ->first();

        // LEGACY — repli conservé pour les données historiques uniquement.
        // Il existait parce qu'un palier HelloAsso à 0 € laissait sa ligne SANS
        // compte : la détection passait alors par la formule (form_slug + tier_id).
        // Depuis le chantier 709A, la ligne parente porte toujours son compte et
        // son montant brut — le chemin principal ci-dessus reprend la main.
        // Ne sert plus qu'aux transactions synchronisées avant ce chantier.
        if ($ligneCotisation === null && $tx->helloasso_form_slug !== null) {
            $ligneCotisation = $tx->lignes()
                ->ligneParenteOuManuelle()
                ->whereNotNull('helloasso_tier_id')
                ->get()
                ->first(fn (TransactionLigne $l) => FormuleAdhesion::query()
                    ->where('helloasso_form_slug', $tx->helloasso_form_slug)
                    ->where('helloasso_tier_id', $l->helloasso_tier_id)
                    ->exists());
        }

        if ($ligneCotisation === null) {
            return null;
        }

        $formule = $this->resolveFormule($tx, $ligneCotisation);

        return DB::transaction(function () use ($tx, $formule): Adhesion {
            // Idempotence forte : une adhésion déjà liée à CETTE transaction est
            // l'adhésion de la transaction, quel que soit son exercice/mode. Le
            // wizard la crée parfois en mode durée, que la recherche par clé
            // ci-dessous ne retrouverait pas toujours (clé = dates, pas exercice),
            // d'où un doublon quand l'observer rejoue creerDepuisTransaction
            // (ex. au "marquer reçu").
            $parTransaction = Adhesion::withTrashed()
                ->where('transaction_id', (int) $tx->id)
                ->first();

            if ($parTransaction !== null) {
                if ($parTransaction->trashed()) {
                    $parTransaction->restore();
                }

                return $parTransaction;
            }

            $datesEtExercice = $this->computeDatesEtExercice($tx, $formule);

            // Idempotence : la clé de recherche dépend du MODE de la formule, jamais
            // de la nullité de l'exercice (qui est désormais toujours renseigné).
            $adhesion = $this->findExistingAdhesion(
                tiersId: (int) $tx->tiers_id,
                parExercice: $this->estParExercice($formule),
                exercice: $datesEtExercice['exercice'],
                dateDebut: $datesEtExercice['date_debut'],
                dateFin: $datesEtExercice['date_fin'],
            );

            if ($adhesion?->trashed()) {
                $adhesion->restore();

                return $adhesion;
            }

            if ($adhesion !== null) {
                return $adhesion; // idempotence : ne pas écraser transaction_id ni formule_adhesion_id
            }

            // SNAPSHOT — net débit/crédit des lignes de ventilation (classes 6/7),
            // exactement la formule de ComptaCheckIntegrityCommand::checkAdhesionMontants
            // (CHECK 4) : ce contrôle d'intégrité définit la vérité de montant_facial,
            // pas l'inverse — toute autre définition ferait remonter chaque adhésion
            // remisée comme incohérente. Un simple sum('montant') des lignes fige le
            // BRUT d'une adhésion remisée (Tâche 11, régression HA-55698 : brut
            // 35,00 € au lieu du net 12,00 €) — seul le net (ligne parent au crédit +
            // ligne de remise au débit qui se compensent) survit à une remise
            // partielle ou totale. No-op strict sur les données existantes : mesuré
            // sur le clone de production svs_accounting, les 26 adhésions actuelles
            // (dont 4 multi-lignes) valident déjà cette définition — CHECK 4 n'a
            // aucune modification à subir.
            $montantFacial = (float) (DB::table('transaction_lignes as tl')
                ->join('comptes as c', 'c.id', '=', 'tl.compte_id')
                ->join('transactions as t', 't.id', '=', 'tl.transaction_id')
                ->where('tl.transaction_id', (int) $tx->id)
                ->where('t.association_id', TenantContext::currentId())
                ->where('c.association_id', TenantContext::currentId())
                ->whereNull('tl.deleted_at')
                ->whereIn('c.classe', [6, 7])
                ->selectRaw('SUM(CASE WHEN c.classe = 6 THEN tl.debit - tl.credit ELSE tl.credit - tl.debit END) as net')
                ->value('net') ?? 0);

            return Adhesion::create([
                'association_id' => TenantContext::currentId(),
                'tiers_id' => (int) $tx->tiers_id,
                'exercice' => $datesEtExercice['exercice'],
                'transaction_id' => (int) $tx->id,
                'formule_adhesion_id' => $formule?->id,
                'date_debut' => $datesEtExercice['date_debut'],
                'date_fin' => $datesEtExercice['date_fin'],
                'saisi_par' => $tx->saisi_par !== null ? (int) $tx->saisi_par : null,
                'montant_facial' => round($montantFacial, 2),
                'deductible_fiscal' => $formule?->deductible_fiscal ?? false,
                'mode' => $formule?->mode ?? 'exercice',
                'duree_mois' => $formule?->duree_mois,
                'label_formule' => $formule?->nom ?? 'Adhésion legacy',
            ]);
        });
    }

    /**
     * Résout la formule applicable selon priorité :
     *   1. HelloAsso — formule auto-créée par la sync (lookup direct helloasso_form_slug + helloasso_tier_id)
     *   2. Formule active sur le compte de ventilation de la ligne cotisation
     *   3. null (adhésion legacy)
     */
    private function resolveFormule(Transaction $tx, TransactionLigne $ligneCotisation): ?FormuleAdhesion
    {
        // Priorité 1 : HelloAsso — formule auto-créée par la sync.
        // helloasso_form_slug n'est posé QUE par HelloAssoSyncService → la présence des
        // 2 colonnes (form_slug sur transaction, tier_id sur ligne) suffit à garantir
        // que cette transaction vient de HelloAsso. Ne PAS conditionner sur
        // helloasso_payment_id : les paliers HelloAsso à 0€ (cotisations offertes,
        // par ex. tier "Cotisation offerte" sur un form Membership) n'ont pas de
        // payment_id côté HA → la garde précédente faisait tomber ces cas en prio 2
        // (formule manuelle), avec un mauvais snapshot fiscal.
        if ($tx->helloasso_form_slug !== null
            && $ligneCotisation->helloasso_tier_id !== null) {
            $formule = FormuleAdhesion::query()
                ->where('helloasso_form_slug', $tx->helloasso_form_slug)
                ->where('helloasso_tier_id', $ligneCotisation->helloasso_tier_id)
                ->first();

            if ($formule !== null) {
                return $formule;
            }
        }

        // Priorité 2 : compte de ventilation → formule active (DC-10a — compte_id
        // est la source unique ; sans compte, pas de formule résolvable).
        if ($ligneCotisation->compte_id !== null) {
            return $this->formuleResolver->resolve((int) $ligneCotisation->compte_id);
        }

        return null;
    }

    /**
     * L'exercice est la SAISON d'adhésion : dérivé de la date de DÉBUT de l'adhésion,
     * pour tous les modes. C'est une information, jamais la clé de dédoublonnage d'une
     * formule en durée (voir findExistingAdhesion).
     *
     * @return array{exercice: int, date_debut: CarbonImmutable, date_fin: ?CarbonImmutable}
     */
    private function computeDatesEtExercice(Transaction $tx, ?FormuleAdhesion $formule): array
    {
        // Mode durée avec dates HelloAsso explicites (Custom) → utiliser les dates du form
        if ($formule !== null && $formule->aDatesFixes()) {
            $debut = CarbonImmutable::parse($formule->helloasso_start_date);

            return [
                'exercice' => $this->exerciceFromDate($debut),
                'date_debut' => $debut,
                'date_fin' => $formule->helloasso_end_date !== null
                    ? CarbonImmutable::parse($formule->helloasso_end_date)
                    : null,
            ];
        }

        // Mode durée avec duree_jours → calcul depuis tx.date (branche avant duree_mois)
        if ($formule !== null && $formule->isModeDuree() && $formule->duree_jours !== null) {
            $debut = CarbonImmutable::parse($tx->date);
            $fin = $debut->addDays((int) $formule->duree_jours)->subDay();

            return [
                'exercice' => $this->exerciceFromDate($debut),
                'date_debut' => $debut,
                'date_fin' => $fin,
            ];
        }

        // Mode durée avec duree_mois → calcul depuis tx.date
        if ($formule !== null && $formule->isModeDuree() && $formule->duree_mois !== null) {
            $debut = CarbonImmutable::parse($tx->date);
            $fin = $debut->addMonths((int) $formule->duree_mois)->subDay();

            return [
                'exercice' => $this->exerciceFromDate($debut),
                'date_debut' => $debut,
                'date_fin' => $fin,
            ];
        }

        if ($formule !== null && $formule->isModeIllimite()) {
            $debut = CarbonImmutable::parse($tx->date);

            return [
                'exercice' => $this->exerciceFromDate($debut),
                'date_debut' => $debut,
                'date_fin' => null,
            ];
        }

        // Mode exercice (ou pas de formule = legacy) → exercice + dates calculées depuis exercice asso
        $exercice = $this->exerciceFromDate($tx->date);
        $exerciceMoisDebut = TenantContext::current()?->exercice_mois_debut ?? 9;
        $debutExercice = CarbonImmutable::create($exercice, $exerciceMoisDebut, 1);
        $finExercice = $debutExercice->addYear()->subDay();

        return [
            'exercice' => $exercice,
            'date_debut' => $debutExercice,
            'date_fin' => $finExercice,
        ];
    }

    /**
     * La déduplication est fondée sur le MODE de la formule, jamais sur la nullité de
     * l'exercice (désormais toujours renseigné) :
     *  - mode exercice (ou sans formule) : clé (tiers, exercice) ;
     *  - mode durée : clé (date_debut, date_fin), pour que deux adhésions successives
     *    d'une même saison — formule trimestrielle renouvelée — restent distinctes ;
     *  - mode illimité : une seule adhésion permanente par tiers.
     */
    private function findExistingAdhesion(int $tiersId, bool $parExercice, int $exercice, ?CarbonImmutable $dateDebut, ?CarbonImmutable $dateFin): ?Adhesion
    {
        $query = Adhesion::withTrashed()->where('tiers_id', $tiersId);

        if ($parExercice) {
            return $this->surCleExercice($query)->where('exercice', $exercice)->first();
        }

        // Mode durée : lookup par (date_debut, date_fin)
        if ($dateDebut !== null && $dateFin !== null) {
            return $query
                ->whereDate('date_debut', $dateDebut->toDateString())
                ->whereDate('date_fin', $dateFin->toDateString())
                ->first();
        }

        // Mode illimite : 1 seule adhésion permanente possible par tiers
        if ($dateDebut !== null && $dateFin === null) {
            return $query
                ->where('mode', 'illimite')
                ->first();
        }

        return null;
    }

    /** Une adhésion sans formule (legacy) suit la clé « exercice », comme computeDatesEtExercice. */
    private function estParExercice(?FormuleAdhesion $formule): bool
    {
        return $formule === null || $formule->isModeExercice();
    }

    /**
     * Restreint la clé (tiers, exercice) aux adhésions « par exercice » (ou de mode nul,
     * ancienne saisie offerte). Avant la complétude de l'exercice, une adhésion en durée
     * ou illimitée avait un exercice nul et n'entrait jamais dans cette clé : le lui
     * renseigner ne doit pas l'y faire entrer, sous peine de fusionner — ou de refuser —
     * un règlement « par exercice » de la même saison.
     *
     * @param  Builder<Adhesion>  $query
     * @return Builder<Adhesion>
     */
    private function surCleExercice(Builder $query): Builder
    {
        return $query->where(function (Builder $q): void {
            $q->whereNull('mode')->orWhere('mode', 'exercice');
        });
    }

    public function creerGratuite(Tiers $tiers, int $exercice, string $motif, User $createur): Adhesion
    {
        return DB::transaction(function () use ($tiers, $exercice, $motif, $createur): Adhesion {
            $existante = Adhesion::withTrashed()
                ->where('tiers_id', (int) $tiers->id)
                ->where('exercice', $exercice)
                ->first();

            if ($existante !== null && ! $existante->trashed()) {
                throw new DomainException(
                    "Ce tiers a déjà une adhésion sur l'exercice {$existante->exercice}-".($existante->exercice + 1).'.'
                );
            }

            if ($existante !== null && $existante->trashed()) {
                $existante->restore();
                $existante->update([
                    'notes' => $motif,
                    'transaction_id' => null,
                    'saisi_par' => (int) $createur->id,
                ]);

                return $existante;
            }

            return Adhesion::create([
                'association_id' => TenantContext::currentId(),
                'tiers_id' => (int) $tiers->id,
                'exercice' => $exercice,
                'transaction_id' => null,
                'notes' => $motif,
                'saisi_par' => (int) $createur->id,
            ]);
        });
    }

    public function creerDepuisWizard(NouvelleAdhesionDTO $dto, User $createur): Adhesion
    {
        $formule = FormuleAdhesion::findOrFail($dto->formuleId);

        return DB::transaction(function () use ($dto, $createur, $formule): Adhesion {
            // 1. Calcul dates / exercice. L'exercice est la saison d'adhésion : dérivé
            // de la date de début dans tous les modes (information, pas clé — D2).
            if ($formule->aDatesFixes()) {
                // Durée avec dates HelloAsso explicites (Custom)
                $dateDebut = Carbon::parse($formule->helloasso_start_date);
                $dateFin = $formule->helloasso_end_date !== null
                    ? Carbon::parse($formule->helloasso_end_date)
                    : null;
                $exercice = $this->exerciceFromDate($dateDebut);
            } elseif ($formule->isModeDuree() && $formule->duree_jours !== null) {
                // Durée en jours (branche avant duree_mois)
                $dateDebut = $dto->dateDebut ?? Carbon::today();
                $dateFin = $dateDebut->copy()->addDays((int) $formule->duree_jours)->subDay();
                $exercice = $this->exerciceFromDate($dateDebut);
            } elseif ($formule->isModeDuree()) {
                $dateDebut = $dto->dateDebut ?? Carbon::today();
                $dateFin = $dateDebut->copy()->addMonths((int) $formule->duree_mois)->subDay();
                $exercice = $this->exerciceFromDate($dateDebut);
            } elseif ($formule->isModeIllimite()) {
                $dateDebut = $dto->dateDebut ?? Carbon::today();
                $dateFin = null;
                $exercice = $this->exerciceFromDate($dateDebut);
            } else {
                $exercice = $dto->exercice ?? $this->exerciceFromDate(Carbon::today());
                $exerciceMoisDebut = TenantContext::current()?->exercice_mois_debut ?? 9;
                $dateDebut = Carbon::create($exercice, $exerciceMoisDebut, 1);
                $dateFin = $dateDebut->copy()->addYear()->subDay();
            }

            // 2. Validation doublon (mode exercice) ou recouvrement (durée, illimité)
            $this->guardAgainstOverlap(
                tiersId: $dto->tiersId,
                parExercice: $formule->isModeExercice(),
                exercice: $exercice,
                dateDebut: $dateDebut,
                dateFin: $dateFin,
            );

            // 3. Création transaction si payée
            $transactionId = null;
            if ($dto->montant > 0) {
                $transactionId = $this->creerTransactionPaiement($dto, $formule, $createur);
            }

            // 4. Création adhésion
            return Adhesion::create([
                'association_id' => TenantContext::currentId(),
                'tiers_id' => $dto->tiersId,
                'exercice' => $exercice,
                'transaction_id' => $transactionId,
                'formule_adhesion_id' => (int) $formule->id,
                'date_debut' => $dateDebut?->toDateString(),
                'date_fin' => $dateFin?->toDateString(),
                'notes' => $dto->notes,
                'saisi_par' => (int) $createur->id,
                // SNAPSHOT
                'montant_facial' => $dto->montant,
                'deductible_fiscal' => $formule->deductible_fiscal,
                'mode' => $formule->mode,
                'duree_mois' => $formule->duree_mois,
                'label_formule' => $formule->nom,
            ]);
        });
    }

    /**
     * Même règle que findExistingAdhesion : la garde suit le MODE de la formule, pas la
     * nullité de l'exercice — sinon elle refuserait une seconde adhésion légitime sur
     * une même saison pour une formule en durée.
     */
    private function guardAgainstOverlap(int $tiersId, bool $parExercice, int $exercice, ?Carbon $dateDebut, ?Carbon $dateFin): void
    {
        if ($parExercice) {
            $existante = $this->surCleExercice(Adhesion::withTrashed()->where('tiers_id', $tiersId))
                ->where('exercice', $exercice)
                ->first();

            if ($existante !== null && $existante->trashed()) {
                throw new DomainException(
                    "Ce tiers a une adhésion annulée sur l'exercice {$exercice}-".($exercice + 1).". Restaurez-la depuis la fiche tiers avant d'en créer une nouvelle."
                );
            }

            if ($existante !== null) {
                throw new DomainException(
                    "Ce tiers a déjà une adhésion sur l'exercice {$exercice}-".($exercice + 1).'.'
                );
            }

            return;
        }

        if ($dateDebut === null || $dateFin === null) {
            return;
        }

        $chevauche = Adhesion::query()
            ->where('tiers_id', $tiersId)
            ->whereNotNull('date_debut')
            ->whereNotNull('date_fin')
            ->where(function ($q) use ($dateDebut, $dateFin): void {
                // Période [dateDebut, dateFin] chevauche [a.date_debut, a.date_fin]
                // <=> dateDebut <= a.date_fin ET dateFin >= a.date_debut
                $q->where('date_debut', '<=', $dateFin->toDateString())
                    ->where('date_fin', '>=', $dateDebut->toDateString());
            })
            ->exists();

        if ($chevauche) {
            throw new DomainException('La période demandée chevauche une adhésion existante du tiers.');
        }
    }

    /**
     * Crée la transaction de paiement via TransactionService::create() pour
     * bénéficier de l'enrichissement PD (411 D / 7xx C + T2 encaissement),
     * de la garde exercice ouvert et du syncer statut dérivé.
     *
     * L'observer AdhesionTransactionLigneObserver est supprimé le temps de la
     * création pour éviter une double création d'adhésion (le wizard la gère).
     */
    private function creerTransactionPaiement(NouvelleAdhesionDTO $dto, FormuleAdhesion $formule, User $createur): int
    {
        if ($dto->datePaiement === null) {
            throw new \InvalidArgumentException('datePaiement est requis lorsque le montant est positif.');
        }

        // Supprimer l'observer adhésion le temps de la création — le wizard
        // gère lui-même la création de l'adhésion après la transaction.
        AdhesionTransactionLigneObserver::$suppress = true;

        try {
            $tx = $this->transactionService->create(
                data: [
                    'type' => TypeTransaction::Recette->value,
                    'date' => $dto->datePaiement,
                    'libelle' => "Cotisation — {$formule->nom}",
                    'montant_total' => $dto->montant,
                    'mode_paiement' => $dto->modePaiement?->value,
                    'tiers_id' => $dto->tiersId,
                    'compte_id' => $dto->compteId,
                    'reference' => $dto->reference,
                ],
                lignes: [
                    [
                        // DC-10a : la formule porte le compte de ventilation (compte_id).
                        'compte_id' => $formule->compte_id,
                        'montant' => $dto->montant,
                    ],
                ],
            );
        } finally {
            AdhesionTransactionLigneObserver::$suppress = false;
        }

        return (int) $tx->id;
    }

    private function exerciceFromDate(\DateTimeInterface $date): int
    {
        $year = (int) $date->format('Y');
        $month = (int) $date->format('n');
        $exerciceMoisDebut = TenantContext::current()?->exercice_mois_debut ?? 9;

        return $month >= $exerciceMoisDebut ? $year : $year - 1;
    }
}
