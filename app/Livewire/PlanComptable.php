<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\Compte;
use App\Models\Famille;
use App\Models\SousCategorie;
use App\Tenant\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Component;

/**
 * Écran « Plan comptable » (DC-7 — dissolution sous_categories → comptes).
 *
 * Remplace l'écran « Sous-catégories » (SousCategorieList) : l'IHM gère
 * désormais les comptes de résultat (classes 6/7), groupés par famille
 * (préfixe à 2 caractères). Le miroir `sous_categories` est entretenu en
 * dessous par les observers (CompteObserver → miroir retour,
 * SousCategorieCompteObserver → garde-fou de convergence) jusqu'à DC-10.
 *
 * Renumérotation : le numéro PCG est l'IDENTITÉ du compte — il se saisit à
 * la création et ne s'édite jamais ici. L'UI ne l'expose pas, et
 * `updateField()` n'accepte que `intitule` (garde serveur contre tout
 * payload forgé qui tenterait de renuméroter un compte porteur d'écritures).
 */
final class PlanComptable extends Component
{
    // ── Modal state ──────────────────────────────────────────────
    public bool $showModal = false;

    // ── Form fields (création uniquement — numéro EN PREMIER) ────
    public string $numero_pcg = '';

    public string $intitule = '';

    // ── Flash message ────────────────────────────────────────────
    public string $flashMessage = '';

    public string $flashType = '';

    public function render(): View
    {
        $comptes = Compte::whereIn('classe', [6, 7])
            ->orderBy('numero_pcg')
            ->get();

        return view('livewire.plan-comptable', [
            // Groupes par préfixe 2 caractères (= famille), ordre code ascendant.
            'groupes' => $comptes
                ->groupBy(fn (Compte $c): string => substr($c->numero_pcg, 0, 2))
                ->sortKeys(),
            'familles' => Famille::pourComptes($comptes),
        ]);
    }

    // ── Création ─────────────────────────────────────────────────

    public function openCreate(): void
    {
        $this->resetForm();
        $this->showModal = true;
    }

    public function save(): void
    {
        $this->validate(
            [
                'numero_pcg' => [
                    'required',
                    'regex:/^[67][0-9A-Z]{2,5}$/',
                    // Unicité par association. Rule::unique interroge la table
                    // brute (sans scope tenant ni SoftDeletes) : le filtre
                    // association_id est donc explicite, et les comptes
                    // soft-deleted comptent aussi — l'index unique
                    // comptes_asso_numero_pcg_unique les inclut de toute façon.
                    Rule::unique('comptes', 'numero_pcg')
                        ->where('association_id', TenantContext::currentId()),
                ],
                'intitule' => 'required|string|max:255',
            ],
            [
                'numero_pcg.regex' => 'Le numéro doit commencer par 6 ou 7 (3 à 6 caractères alphanumériques majuscules).',
                'numero_pcg.unique' => 'Ce numéro de compte existe déjà.',
            ],
        );

        Compte::create([
            'association_id' => TenantContext::currentId(),
            'numero_pcg' => $this->numero_pcg,
            'intitule' => $this->intitule,
            'classe' => (int) substr($this->numero_pcg, 0, 1),
            'actif' => true,
            'est_systeme' => false,
            'lettrable' => false,
            'pour_inscriptions' => false,
        ]);
        // La famille orpheline + le miroir sous_categorie se matérialisent
        // seuls via CompteObserver::created (échafaudage DC-7).

        $this->showModal = false;
        $this->resetForm();
    }

    // ── Édition inline ───────────────────────────────────────────

    public function updateField(int $id, string $field, string $value): void
    {
        // Garde serveur : seule l'édition de l'intitulé est permise. Le numéro
        // PCG est l'identité du compte — un compte porteur d'écritures
        // (transaction_lignes.compte_id) ne doit jamais être renuméroté.
        // L'UI ne l'expose pas ; tout payload forgé est ignoré ici.
        if ($field !== 'intitule') {
            return;
        }

        $compte = Compte::findOrFail($id);

        if ($compte->est_systeme) {
            $this->flashMessage = 'Ce compte système ne peut pas être modifié.';
            $this->flashType = 'danger';

            return;
        }

        $validator = validator(['intitule' => $value], ['intitule' => 'required|string|max:255']);

        if ($validator->fails()) {
            $this->flashMessage = $validator->errors()->first('intitule');
            $this->flashType = 'danger';

            return;
        }

        $compte->update(['intitule' => $value]);

        // Miroir : répercuter le nouveau nom sur la sous-catégorie legacy pour
        // que les écrans non encore basculés (DC-8) l'affichent aussi.
        // N'altère pas code_cerfa → SousCategorieCompteObserver::updated no-op.
        SousCategorie::where('code_cerfa', $compte->numero_pcg)
            ->first()
            ?->update(['nom' => $value]);
    }

    /**
     * Édition inline du nom de famille (en-tête de groupe).
     *
     * Défensif : si la famille n'existe pas encore pour un préfixe affiché
     * (compte créé avant DC-1, observer contourné…), elle est matérialisée à
     * la volée (nom = code) avant d'être renommée.
     */
    public function updateFamilleNom(string $code, string $nom): void
    {
        $nom = trim($nom);

        if (! preg_match('/^[67][0-9A-Z]$/', $code) || $nom === '' || mb_strlen($nom) > 255) {
            return;
        }

        $famille = Famille::firstOrCreate(
            ['association_id' => (int) TenantContext::currentId(), 'code' => $code],
            ['nom' => $code],
        );

        $famille->update(['nom' => $nom]);
    }

    // ── Suppression ──────────────────────────────────────────────

    public function delete(int $id): void
    {
        $compte = Compte::findOrFail($id);

        if ($compte->est_systeme) {
            $this->flashMessage = 'Ce compte système ne peut pas être supprimé.';
            $this->flashType = 'danger';

            return;
        }

        // Garde explicite obligatoire : transaction_lignes.compte_id est
        // nullOnDelete — aucune contrainte DB ne protège un compte porteur
        // d'écritures (contrairement au legacy sous_categorie_id en RESTRICT).
        if ($compte->lignes()->exists()) {
            $this->flashMessage = 'Suppression impossible : ce compte porte des écritures.';
            $this->flashType = 'danger';

            return;
        }

        $miroir = SousCategorie::where('code_cerfa', $compte->numero_pcg)->first();

        try {
            DB::transaction(function () use ($compte, $miroir): void {
                // Le miroir d'abord : ses FK legacy en RESTRICT (budget_lines,
                // transaction_lignes.sous_categorie_id, provisions…) jettent si
                // référencé → rollback complet, le compte reste intact.
                // Reprend les gardes DB de l'ancien écran Sous-catégories.
                $miroir?->delete();

                // forceDelete (pas soft) : l'index unique (association_id,
                // numero_pcg) inclut les lignes soft-deleted — un soft delete
                // rendrait le numéro définitivement irrecréable à l'écran.
                // Sans écriture ni référence (gardes ci-dessus), le hard
                // delete est sûr ; les FK compte_id restantes sont nullOnDelete.
                $compte->forceDelete();
            });
        } catch (QueryException $e) {
            if ($e->getCode() === '23000') {
                $this->flashMessage = 'Suppression impossible : cet élément est utilisé dans les données de l\'application.';
                $this->flashType = 'danger';

                return;
            }
            throw $e;
        }
    }

    // ── Private helpers ──────────────────────────────────────────

    private function resetForm(): void
    {
        $this->numero_pcg = '';
        $this->intitule = '';
        $this->flashMessage = '';
        $this->flashType = '';
        $this->resetValidation();
    }
}
