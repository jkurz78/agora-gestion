<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Compte;
use App\Models\CompteBancaire;
use App\Tenant\TenantScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

/**
 * Propage les attributs bancaires de la fiche CompteBancaire vers le compte 512X
 * correspondant du plan comptable.
 *
 * `BancairesSeeder` recopie ces attributs dans `comptes` en INSERT IGNORE : la
 * copie n'est faite qu'à la création du compte, jamais rafraîchie. Sans cet
 * observer, toute correction ultérieure sur la fiche bancaire reste invisible du
 * moteur comptable, qui ne lit que `comptes` — divergence silencieuse.
 *
 * Constaté en recette le 2026-07-29 : date du solde initial corrigée au
 * 31/08/2024 sur la fiche, restée au 31/08/2025 sur le compte 5121. L'à-nouveau
 * de clôture s'est appuyé sur la valeur périmée et a produit des soldes
 * d'ouverture faux, sans le moindre signal.
 *
 * L'intitulé n'est volontairement PAS propagé : le libellé d'un compte est
 * librement modifiable côté plan comptable (décision D3 de la dissolution
 * sous_categories → comptes) et peut légitimement différer du nom de la banque.
 */
final class CompteBancaireObserver
{
    /** @var list<string> */
    private const ATTRIBUTS_PROPAGES = [
        'iban',
        'bic',
        'domiciliation',
        'solde_initial',
        'date_solde_initial',
    ];

    /**
     * Dote toute fiche bancaire nouvelle de son compte 512X.
     *
     * `BancairesSeeder` ne tourne qu'à la migration initiale et à la finalisation
     * de l'onboarding : un compte bancaire créé depuis Paramètres n'avait donc
     * aucun compte au plan comptable. Toutes ses écritures partaient sans
     * contrepartie de trésorerie — `CompteTresorerieResolver` journalise et rend
     * la main, la transaction reste `equilibree = false`, et le backfill ne la
     * rattrape pas puisque son compte de trésorerie reste irrésoluble. Le compte
     * bloquait en outre la clôture à vie s'il portait un solde d'ouverture, la
     * garde de reprise ne pouvant pas couvrir un compte absent du plan comptable.
     *
     * Constaté le 2026-08-01, en vérifiant l'origine des numéros 512X.
     */
    public function created(CompteBancaire $compteBancaire): void
    {
        $associationId = $compteBancaire->association_id;

        // La table n'existe pas encore : les migrations antérieures à la création
        // de `comptes` créent des fiches bancaires — le compte système « Remises
        // en banque ». Une fiche sans association ne peut pas non plus être
        // rattachée : même migration, avant l'arrivée du multi-tenant.
        if ($associationId === null || ! Schema::hasTable('comptes')) {
            return;
        }

        // Cet observer tient à jour un plan comptable existant ; il n'en fabrique
        // pas un. Une association sans aucun compte est en cours de provisionnement
        // — onboarding, migration, jeu d'essai — et c'est ComptesProvisioningService
        // qui la dotera, comptes bancaires compris. Y greffer un 512X isolé
        // devancerait le seeder sans rien garantir du reste du plan.
        if (! $this->comptesDuTenant((int) $associationId)->exists()) {
            return;
        }

        if ($this->comptesDuTenant((int) $associationId)
            ->where('compte_bancaire_id', (int) $compteBancaire->id)
            ->exists()
        ) {
            return;
        }

        Compte::create([
            'association_id' => (int) $associationId,
            'numero_pcg' => $this->prochainNumeroBancaire((int) $associationId),
            'intitule' => (string) $compteBancaire->nom,
            'classe' => 5,
            'parent_compte_id' => null,
            'actif' => true,
            'est_systeme' => true,
            'pour_inscriptions' => false,
            'lettrable' => false,
            'iban' => $compteBancaire->iban,
            'bic' => $compteBancaire->bic,
            'domiciliation' => $compteBancaire->domiciliation,
            'solde_initial' => $compteBancaire->solde_initial,
            'date_solde_initial' => $compteBancaire->date_solde_initial,
            'compte_bancaire_id' => (int) $compteBancaire->id,
        ]);
    }

    public function updated(CompteBancaire $compteBancaire): void
    {
        $modifies = array_filter(
            self::ATTRIBUTS_PROPAGES,
            static fn (string $attribut): bool => $compteBancaire->wasChanged($attribut),
        );

        if ($modifies === []) {
            return;
        }

        $compte = Compte::where('compte_bancaire_id', (int) $compteBancaire->id)->first();

        if ($compte === null) {
            return;
        }

        $compte->forceFill(
            array_combine(
                $modifies,
                array_map(
                    static fn (string $attribut): mixed => $compteBancaire->getAttribute($attribut),
                    $modifies,
                ),
            )
        )->save();
    }

    /**
     * Le numéro qui suit le plus élevé déjà attribué, jamais un numéro libéré.
     *
     * Supprimer une fiche bancaire annule `compte_bancaire_id` (nullOnDelete)
     * mais laisse le compte et ses écritures au plan comptable : son numéro est
     * pris à vie. Le rang recalculé de `BancairesSeeder`
     * (`ROW_NUMBER() … ORDER BY id`) glissait au contraire vers le numéro
     * libéré, où l'`INSERT IGNORE` le faisait échouer en silence.
     *
     * La lecture est bornée à l'association de la fiche plutôt qu'au tenant
     * courant : l'observer doit numéroter correctement même hors requête HTTP —
     * seeders, imports, tests — où `TenantContext` peut ne pas être booté et où
     * le scope global rendrait la table vide, donc tous les comptes en 5121.
     */
    private function prochainNumeroBancaire(int $associationId): string
    {
        $dernierRang = $this->comptesDuTenant($associationId)
            ->where('numero_pcg', 'LIKE', '512_%')
            ->pluck('numero_pcg')
            ->map(static fn (string $numero): int => (int) substr($numero, 3))
            ->max();

        return '512'.(((int) $dernierRang) + 1);
    }

    /** @return Builder<Compte> */
    private function comptesDuTenant(int $associationId): Builder
    {
        return Compte::withTrashed()
            ->withoutGlobalScope(TenantScope::class)
            ->where('association_id', $associationId);
    }
}
