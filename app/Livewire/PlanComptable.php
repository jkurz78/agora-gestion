<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\Compte;
use App\Models\Famille;
use App\Tenant\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Component;

/**
 * Écran « Plan comptable ».
 *
 * Gère les comptes de résultat (classes 6/7) et les comptes d'immobilisation
 * et d'amortissement (classe 2), groupés par famille
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
        $comptes = Compte::whereIn('classe', [2, 6, 7])
            ->withCount('lignes')
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
        // La famille orpheline se matérialise via CompteObserver::created.

        $this->showModal = false;
        $this->resetForm();
    }

    // ── Édition inline ───────────────────────────────────────────

    /**
     * Le booléen retourné permet au blade (Alpine) de restaurer la valeur
     * d'origine dans la cellule quand le serveur refuse — sans lui, la
     * cellule wire:ignore.self continue d'afficher la valeur refusée et
     * l'utilisateur croit la modification acceptée (constat recette R-3).
     */
    public function updateField(int $id, string $field, string $value): bool
    {
        // Garde serveur : intitulé toujours éditable ; numéro éditable tant
        // que le compte est vierge (D3 : l'identité devient immuable dès la
        // première écriture). Tout autre champ forgé est ignoré ici.
        if (! in_array($field, ['intitule', 'numero_pcg'], true)) {
            return false;
        }

        $compte = Compte::findOrFail($id);

        if ($compte->est_systeme) {
            $this->flashMessage = 'Ce compte système ne peut pas être modifié.';
            $this->flashType = 'danger';

            return false;
        }

        if ($field === 'numero_pcg') {
            return $this->renumeroter($compte, $value);
        }

        $validator = validator(['intitule' => $value], ['intitule' => 'required|string|max:255']);

        if ($validator->fails()) {
            $this->flashMessage = $validator->errors()->first('intitule');
            $this->flashType = 'danger';

            return false;
        }

        $compte->update(['intitule' => $value]);

        return true;
    }

    /**
     * Renumérote un compte vierge (D3 : immuable dès la première écriture).
     * La classe est conservée : les usages (dons, cotisations…), budgets et
     * formules qui pointent ce compte supposent sa polarité 6/7.
     */
    private function renumeroter(Compte $compte, string $numero): bool
    {
        if ($compte->lignes()->exists()) {
            $this->flashMessage = 'Renumérotation impossible : ce compte porte des écritures.';
            $this->flashType = 'danger';

            return false;
        }

        $validator = validator(
            ['numero_pcg' => $numero],
            [
                'numero_pcg' => [
                    'required',
                    'regex:/^[67][0-9A-Z]{2,5}$/',
                    Rule::unique('comptes', 'numero_pcg')
                        ->where('association_id', TenantContext::currentId())
                        ->ignore($compte->id),
                ],
            ],
            [
                'numero_pcg.regex' => 'Le numéro doit commencer par 6 ou 7 (3 à 6 caractères alphanumériques majuscules).',
                'numero_pcg.unique' => 'Ce numéro de compte existe déjà.',
            ],
        );

        if ($validator->fails()) {
            $this->flashMessage = $validator->errors()->first('numero_pcg');
            $this->flashType = 'danger';

            return false;
        }

        if ((int) substr($numero, 0, 1) !== (int) $compte->classe) {
            $this->flashMessage = "Le numéro doit rester en classe {$compte->classe}.";
            $this->flashType = 'danger';

            return false;
        }

        $compte->update(['numero_pcg' => $numero]);
        // La famille orpheline du nouveau préfixe se matérialise via
        // CompteObserver::updated.

        return true;
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
        // d'écritures.
        if ($compte->lignes()->exists()) {
            $this->flashMessage = 'Suppression impossible : ce compte porte des écritures.';
            $this->flashType = 'danger';

            return;
        }

        try {
            $compte->forceDelete();
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
