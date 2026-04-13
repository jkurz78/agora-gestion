<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\StatutRapprochement;
use App\Enums\StatutReglement;
use App\Models\CompteBancaire;
use App\Models\RapprochementBancaire;
use App\Models\Transaction;
use App\Models\VirementInterne;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class RapprochementBancaireService
{
    public function __construct(
        private readonly ExerciceService $exerciceService,
    ) {}

    /**
     * Calcule le solde d'ouverture : solde_fin du dernier rapprochement verrouillé,
     * ou solde_initial du compte si aucun n'existe.
     */
    public function calculerSoldeOuverture(CompteBancaire $compte): float
    {
        $dernier = RapprochementBancaire::where('compte_id', $compte->id)
            ->where('statut', StatutRapprochement::Verrouille)
            ->orderByDesc('date_fin')
            ->orderByDesc('id')
            ->first();

        return $dernier ? (float) $dernier->solde_fin : (float) $compte->solde_initial;
    }

    /**
     * Crée un nouveau rapprochement pour un compte.
     * Lève RuntimeException si un rapprochement "en cours" existe déjà sur ce compte.
     */
    public function create(CompteBancaire $compte, string $dateFin, float $soldeFin): RapprochementBancaire
    {
        $this->exerciceService->assertOuvert(
            $this->exerciceService->anneeForDate(CarbonImmutable::parse($dateFin))
        );

        $enCours = RapprochementBancaire::where('compte_id', $compte->id)
            ->where('statut', StatutRapprochement::EnCours)
            ->exists();

        if ($enCours) {
            throw new RuntimeException('Un rapprochement est déjà en cours pour ce compte.');
        }

        return DB::transaction(function () use ($compte, $dateFin, $soldeFin) {
            return RapprochementBancaire::create([
                'compte_id' => $compte->id,
                'date_fin' => $dateFin,
                'solde_ouverture' => $this->calculerSoldeOuverture($compte),
                'solde_fin' => $soldeFin,
                'statut' => StatutRapprochement::EnCours,
                'saisi_par' => auth()->id(),
            ]);
        });
    }

    /**
     * Crée un rapprochement directement verrouillé (auto-généré par la sync HelloAsso).
     * Ne vérifie pas s'il existe un rapprochement en cours — indépendant du workflow manuel.
     *
     * @param  list<int>  $transactionIds  IDs des transactions à pointer
     */
    public function createVerrouilleAuto(
        CompteBancaire $compte,
        string $dateFin,
        float $soldeFin,
        array $transactionIds,
        int $virementId,
    ): RapprochementBancaire {
        $this->exerciceService->assertOuvert(
            $this->exerciceService->anneeForDate(CarbonImmutable::parse($dateFin))
        );

        return DB::transaction(function () use ($compte, $dateFin, $soldeFin, $transactionIds, $virementId) {
            $rapprochement = RapprochementBancaire::create([
                'compte_id' => $compte->id,
                'date_fin' => $dateFin,
                'solde_ouverture' => $this->calculerSoldeOuverture($compte),
                'solde_fin' => $soldeFin,
                'statut' => StatutRapprochement::Verrouille,
                'verrouille_at' => now(),
                'saisi_par' => auth()->id() ?? 1,
            ]);

            if (! empty($transactionIds)) {
                Transaction::whereIn('id', $transactionIds)
                    ->update([
                        'rapprochement_id' => $rapprochement->id,
                        'statut_reglement' => StatutReglement::Pointe->value,
                    ]);
            }

            VirementInterne::where('id', $virementId)
                ->update(['rapprochement_source_id' => $rapprochement->id]);

            return $rapprochement;
        });
    }

    /**
     * Calcule le solde pointé courant :
     * solde_ouverture + entrées pointées − sorties pointées.
     */
    public function calculerSoldePointage(RapprochementBancaire $rapprochement): float
    {
        $solde = (float) $rapprochement->solde_ouverture;

        $solde += (float) Transaction::where('rapprochement_id', $rapprochement->id)
            ->selectRaw("SUM(CASE WHEN type = 'depense' THEN -montant_total ELSE montant_total END) as total")
            ->value('total');
        $solde += (float) VirementInterne::where('rapprochement_destination_id', $rapprochement->id)->sum('montant');
        $solde -= (float) VirementInterne::where('rapprochement_source_id', $rapprochement->id)->sum('montant');

        return round($solde, 2);
    }

    /**
     * Calcule l'écart : solde_fin - solde_pointage.
     */
    public function calculerEcart(RapprochementBancaire $rapprochement): float
    {
        return round((float) $rapprochement->solde_fin - $this->calculerSoldePointage($rapprochement), 2);
    }

    /**
     * Pointe ou dé-pointe une transaction pour ce rapprochement.
     * Types: 'depense', 'recette', 'virement_source', 'virement_destination'
     */
    public function toggleTransaction(RapprochementBancaire $rapprochement, string $type, int $id): void
    {
        if ($rapprochement->isVerrouille()) {
            throw new RuntimeException('Impossible de modifier un rapprochement verrouillé.');
        }

        $this->exerciceService->assertOuvert(
            $this->exerciceService->anneeForDate(CarbonImmutable::parse($rapprochement->date_fin))
        );

        // Verify account ownership before modifying
        if (str_starts_with($type, 'virement')) {
            $virement = VirementInterne::findOrFail($id);
            $expectedField = $type === 'virement_source' ? 'compte_source_id' : 'compte_destination_id';
            if ((int) $virement->{$expectedField} !== (int) $rapprochement->compte_id) {
                throw new \InvalidArgumentException("La transaction n'appartient pas au compte de ce rapprochement.");
            }
        } else {
            $model = match ($type) {
                'depense', 'recette' => Transaction::findOrFail($id),
                default => throw new \InvalidArgumentException("Type de transaction inconnu : {$type}"),
            };
            if ((int) $model->compte_id !== (int) $rapprochement->compte_id) {
                throw new \InvalidArgumentException("La transaction n'appartient pas au compte de ce rapprochement.");
            }
        }

        DB::transaction(function () use ($rapprochement, $type, $id) {
            if (str_starts_with($type, 'virement')) {
                $this->toggleVirement($rapprochement, $type, $id);

                return;
            }

            $model = match ($type) {
                'depense', 'recette' => Transaction::findOrFail($id),
                default => throw new \InvalidArgumentException("Type de transaction inconnu : {$type}"),
            };

            if ((int) $model->rapprochement_id === $rapprochement->id) {
                $model->rapprochement_id = null;
                $model->statut_reglement = $model->remise_id !== null
                    ? StatutReglement::Recu
                    : StatutReglement::EnAttente;
            } else {
                $model->rapprochement_id = $rapprochement->id;
                $model->statut_reglement = StatutReglement::Pointe;
            }
            $model->save();
        });
    }

    // VirementInterne n'a pas de champ 'pointe' — le pointage est indiqué
    // par rapprochement_source_id / rapprochement_destination_id.
    private function toggleVirement(RapprochementBancaire $rapprochement, string $type, int $id): void
    {
        $virement = VirementInterne::findOrFail($id);
        $field = $type === 'virement_source' ? 'rapprochement_source_id' : 'rapprochement_destination_id';

        if ((int) $virement->{$field} === $rapprochement->id) {
            $virement->{$field} = null;
        } else {
            $virement->{$field} = $rapprochement->id;
        }
        $virement->save();
    }

    /**
     * Supprime un rapprochement "en cours" et dépointe toutes ses opérations.
     * Lève RuntimeException si le rapprochement est verrouillé.
     */
    public function supprimer(RapprochementBancaire $rapprochement): void
    {
        if ($rapprochement->isVerrouille()) {
            throw new RuntimeException('Impossible de supprimer un rapprochement verrouillé.');
        }

        $this->exerciceService->assertOuvert(
            $this->exerciceService->anneeForDate(CarbonImmutable::parse($rapprochement->date_fin))
        );

        DB::transaction(function () use ($rapprochement) {
            $id = $rapprochement->id;

            Transaction::where('rapprochement_id', $id)->each(function (Transaction $tx): void {
                $tx->update([
                    'rapprochement_id' => null,
                    'statut_reglement' => $tx->remise_id !== null
                        ? StatutReglement::Recu->value
                        : StatutReglement::EnAttente->value,
                ]);
            });

            VirementInterne::where('rapprochement_source_id', $id)
                ->update(['rapprochement_source_id' => null]);

            VirementInterne::where('rapprochement_destination_id', $id)
                ->update(['rapprochement_destination_id' => null]);

            $rapprochement->delete();
        });
    }

    /**
     * Déverrouille le rapprochement s'il est le dernier verrouillé du compte
     * et qu'aucun rapprochement en cours n'existe sur ce compte.
     */
    public function deverrouiller(RapprochementBancaire $rapprochement): void
    {
        if (! $rapprochement->isVerrouille()) {
            throw new RuntimeException("Ce rapprochement n'est pas verrouillé.");
        }

        $this->exerciceService->assertOuvert(
            $this->exerciceService->anneeForDate(CarbonImmutable::parse($rapprochement->date_fin))
        );

        $enCours = RapprochementBancaire::where('compte_id', $rapprochement->compte_id)
            ->where('statut', StatutRapprochement::EnCours)
            ->exists();

        if ($enCours) {
            throw new RuntimeException('Impossible de déverrouiller : un rapprochement est en cours sur ce compte.');
        }

        $dernierVerrouille = RapprochementBancaire::where('compte_id', $rapprochement->compte_id)
            ->where('statut', StatutRapprochement::Verrouille)
            ->orderByDesc('date_fin')
            ->orderByDesc('id')
            ->value('id');

        if ($dernierVerrouille !== $rapprochement->id) {
            throw new RuntimeException('Seul le dernier rapprochement verrouillé peut être déverrouillé.');
        }

        DB::transaction(function () use ($rapprochement) {
            $rapprochement->statut = StatutRapprochement::EnCours;
            $rapprochement->verrouille_at = null;
            $rapprochement->save();
        });
    }

    /**
     * Verrouille le rapprochement. L'écart doit être 0.
     */
    public function verrouiller(RapprochementBancaire $rapprochement): void
    {
        $this->exerciceService->assertOuvert(
            $this->exerciceService->anneeForDate(CarbonImmutable::parse($rapprochement->date_fin))
        );

        if ((int) round($this->calculerEcart($rapprochement) * 100) !== 0) {
            throw new RuntimeException("Le rapprochement ne peut être verrouillé que si l'écart est nul.");
        }

        DB::transaction(function () use ($rapprochement) {
            $rapprochement->statut = StatutRapprochement::Verrouille;
            $rapprochement->verrouille_at = now();
            $rapprochement->save();
        });
    }
}
