<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\StatutRapprochement;
use App\Models\CompteBancaire;
use App\Models\Cotisation;
use App\Models\Depense;
use App\Models\Don;
use App\Models\RapprochementBancaire;
use App\Models\Recette;
use App\Models\VirementInterne;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class RapprochementBancaireService
{
    /**
     * Calcule le solde d'ouverture : solde_fin du dernier rapprochement verrouillé,
     * ou solde_initial du compte si aucun n'existe.
     */
    public function calculerSoldeOuverture(CompteBancaire $compte): float
    {
        $dernier = RapprochementBancaire::where('compte_id', $compte->id)
            ->where('statut', StatutRapprochement::Verrouille)
            ->orderByDesc('date_fin')
            ->first();

        return $dernier ? (float) $dernier->solde_fin : (float) $compte->solde_initial;
    }

    /**
     * Crée un nouveau rapprochement pour un compte.
     * Lève RuntimeException si un rapprochement "en cours" existe déjà sur ce compte.
     */
    public function create(CompteBancaire $compte, string $dateFin, float $soldeFin): RapprochementBancaire
    {
        $enCours = RapprochementBancaire::where('compte_id', $compte->id)
            ->where('statut', StatutRapprochement::EnCours)
            ->exists();

        if ($enCours) {
            throw new RuntimeException("Un rapprochement est déjà en cours pour ce compte.");
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
     * Calcule le solde pointé courant :
     * solde_ouverture + entrées pointées − sorties pointées.
     */
    public function calculerSoldePointage(RapprochementBancaire $rapprochement): float
    {
        $solde = (float) $rapprochement->solde_ouverture;

        $solde += (float) Recette::where('rapprochement_id', $rapprochement->id)->sum('montant_total');
        $solde += (float) Don::where('rapprochement_id', $rapprochement->id)->sum('montant');
        $solde += (float) Cotisation::where('rapprochement_id', $rapprochement->id)->sum('montant');
        $solde += (float) VirementInterne::where('rapprochement_destination_id', $rapprochement->id)->sum('montant');
        $solde -= (float) Depense::where('rapprochement_id', $rapprochement->id)->sum('montant_total');
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
     * Types: 'depense', 'recette', 'don', 'cotisation', 'virement_source', 'virement_destination'
     */
    public function toggleTransaction(RapprochementBancaire $rapprochement, string $type, int $id): void
    {
        if ($rapprochement->isVerrouille()) {
            throw new RuntimeException("Impossible de modifier un rapprochement verrouillé.");
        }

        DB::transaction(function () use ($rapprochement, $type, $id) {
            if (str_starts_with($type, 'virement')) {
                $this->toggleVirement($rapprochement, $type, $id);
                return;
            }

            $model = match ($type) {
                'depense' => Depense::findOrFail($id),
                'recette' => Recette::findOrFail($id),
                'don' => Don::findOrFail($id),
                'cotisation' => Cotisation::findOrFail($id),
            };

            if ((int) $model->rapprochement_id === $rapprochement->id) {
                $model->rapprochement_id = null;
                $model->pointe = false;
            } else {
                $model->rapprochement_id = $rapprochement->id;
                $model->pointe = true;
            }
            $model->save();
        });
    }

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
     * Verrouille le rapprochement. L'écart doit être 0.
     */
    public function verrouiller(RapprochementBancaire $rapprochement): void
    {
        if ($this->calculerEcart($rapprochement) !== 0.0) {
            throw new RuntimeException("Le rapprochement ne peut être verrouillé que si l'écart est nul.");
        }

        DB::transaction(function () use ($rapprochement) {
            $rapprochement->statut = StatutRapprochement::Verrouille;
            $rapprochement->verrouille_at = now();
            $rapprochement->save();
        });
    }
}
