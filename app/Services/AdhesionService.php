<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\TypeTransaction;
use App\Enums\UsageComptable;
use App\Models\Adhesion;
use App\Models\FormuleAdhesion;
use App\Models\HelloAssoTierMapping;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Services\Adhesion\SousCategorieFormuleResolver;
use App\Tenant\TenantContext;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

final class AdhesionService
{
    public function __construct(
        private readonly ExerciceService $exerciceService,
        private readonly SousCategorieFormuleResolver $formuleResolver,
    ) {}

    public function creerDepuisTransaction(Transaction $tx): ?Adhesion
    {
        if ($tx->type !== TypeTransaction::Recette) {
            return null;
        }

        if (empty($tx->tiers_id)) {
            return null;
        }

        $ligneCotisation = $tx->lignes()
            ->whereHas('sousCategorie.usages', function ($q): void {
                $q->where('usage', UsageComptable::Cotisation->value);
            })
            ->first();

        if ($ligneCotisation === null) {
            return null;
        }

        $formule = $this->resolveFormule($tx, $ligneCotisation);

        return DB::transaction(function () use ($tx, $formule): Adhesion {
            $datesEtExercice = $this->computeDatesEtExercice($tx, $formule);

            // Idempotence : lookup selon mode
            $adhesion = $this->findExistingAdhesion(
                tiersId: (int) $tx->tiers_id,
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

            return Adhesion::create([
                'association_id' => TenantContext::currentId(),
                'tiers_id' => (int) $tx->tiers_id,
                'exercice' => $datesEtExercice['exercice'],
                'transaction_id' => (int) $tx->id,
                'formule_adhesion_id' => $formule?->id,
                'date_debut' => $datesEtExercice['date_debut'],
                'date_fin' => $datesEtExercice['date_fin'],
                'saisi_par' => $tx->saisi_par !== null ? (int) $tx->saisi_par : null,
            ]);
        });
    }

    /**
     * Résout la formule applicable selon priorité :
     *   1. Mapping HelloAsso (form_slug, tier_id) si transaction est HelloAsso
     *   2. Formule active sur la sous-catégorie de la ligne cotisation
     *   3. null (adhésion legacy)
     */
    private function resolveFormule(Transaction $tx, TransactionLigne $ligneCotisation): ?FormuleAdhesion
    {
        // Priorité 1 : HelloAsso
        if ($tx->helloasso_payment_id !== null
            && $tx->helloasso_form_slug !== null
            && $ligneCotisation->helloasso_tier_id !== null) {
            $mapping = HelloAssoTierMapping::query()
                ->where('helloasso_form_slug', $tx->helloasso_form_slug)
                ->where('helloasso_tier_id', $ligneCotisation->helloasso_tier_id)
                ->where('target_type', FormuleAdhesion::class)
                ->first();

            if ($mapping !== null) {
                $target = $mapping->target;

                if ($target instanceof FormuleAdhesion) {
                    return $target;
                }
            }
        }

        // Priorité 2 : sous-cat → formule active
        if ($ligneCotisation->sous_categorie_id !== null) {
            return $this->formuleResolver->resolve((int) $ligneCotisation->sous_categorie_id);
        }

        return null;
    }

    /**
     * @return array{exercice: ?int, date_debut: ?CarbonImmutable, date_fin: ?CarbonImmutable}
     */
    private function computeDatesEtExercice(Transaction $tx, ?FormuleAdhesion $formule): array
    {
        if ($formule !== null && $formule->isModeDuree()) {
            $debut = CarbonImmutable::parse($tx->date);
            $fin = $debut->addMonths((int) $formule->duree_mois);

            return [
                'exercice' => null,
                'date_debut' => $debut,
                'date_fin' => $fin,
            ];
        }

        return [
            'exercice' => $this->exerciceFromDate($tx->date),
            'date_debut' => null,
            'date_fin' => null,
        ];
    }

    private function findExistingAdhesion(int $tiersId, ?int $exercice, ?CarbonImmutable $dateDebut, ?CarbonImmutable $dateFin): ?Adhesion
    {
        $query = Adhesion::withTrashed()->where('tiers_id', $tiersId);

        if ($exercice !== null) {
            return $query->where('exercice', $exercice)->first();
        }

        return $query
            ->whereDate('date_debut', $dateDebut?->toDateString())
            ->whereDate('date_fin', $dateFin?->toDateString())
            ->first();
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

    private function exerciceFromDate(\DateTimeInterface $date): int
    {
        $year = (int) $date->format('Y');
        $month = (int) $date->format('n');
        $exerciceMoisDebut = TenantContext::current()?->exercice_mois_debut ?? 9;

        return $month >= $exerciceMoisDebut ? $year : $year - 1;
    }
}
