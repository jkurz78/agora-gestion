<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Association;
use App\Models\Categorie;
use App\Models\Compte;
use App\Models\CompteBancaire;
use App\Models\SousCategorie;
use App\Models\User;
use App\Services\Compta\Migrations\BancairesSeeder;
use App\Services\Compta\Migrations\SystemeSeeder;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * Trait Pest pour établir le contexte partie double dans les tests Feature PD.
 *
 * Factorise les ~65 lignes de beforeEach répétées dans 5 fichiers tests :
 * - TransactionServicePartieDoubleTest
 * - FactureServicePartieDoubleTest
 * - FactureServicePartieDoubleEncaissementTest
 * - RemiseBancaireServicePartieDoubleTest
 * - ReglementOperationServicePartieDoubleTest
 *
 * Usage : appeler `$this->setupPartieDoubleContext()` dans le beforeEach du test.
 *
 * Expose sur $this :
 * - association, user (admin)
 * - iban, compteBancaire, compte512X
 * - sc706, compte706 (catégorie recette 706)
 */
trait CreatesPartieDoubleContext
{
    /**
     * Établit le contexte partie double commun à tous les tests PD Feature.
     *
     * - Crée une association + un user admin
     * - Boote TenantContext + session
     * - Seed comptes système (411, 401, 5112) + force 530
     * - Crée un CompteBancaire avec IBAN connu + Compte 512X via BancairesSeeder
     * - Crée une catégorie recette + sous-catégorie 706 + Compte 706
     * - Active config('compta.use_partie_double') = true
     */
    public function setupPartieDoubleContext(): void
    {
        $this->association = Association::factory()->create();
        $this->user = User::factory()->create();
        $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);

        TenantContext::boot($this->association);
        session(['current_association_id' => $this->association->id]);
        $this->actingAs($this->user);

        // Activer le mode partie double
        Config::set('compta.use_partie_double', true);

        // Comptes système : 411, 401, 5112
        SystemeSeeder::seed();

        // Forcer 530 (Caisse — espèces) : conditionnel dans SystemeSeeder
        // (requis sans transaction espèces préexistante)
        $tenantId = (int) TenantContext::currentId();
        $isSqlite = DB::getDriverName() === 'sqlite';
        $insertClause = $isSqlite ? 'INSERT OR IGNORE' : 'INSERT IGNORE';
        DB::statement(<<<SQL
            {$insertClause} INTO comptes
                (association_id, numero_pcg, intitule, classe, actif, est_systeme, pour_inscriptions, lettrable, created_at, updated_at)
            VALUES
                ({$tenantId}, '530', 'Caisse (espèces)', 5, 1, 1, 0, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        SQL);

        // CompteBancaire + Compte 512X correspondant (BancairesSeeder copie l'IBAN)
        $this->iban = 'FR7612345000012345678901234';
        $this->compteBancaire = CompteBancaire::factory()->create([
            'association_id' => $this->association->id,
            'iban' => $this->iban,
        ]);
        BancairesSeeder::seed();
        $this->compte512X = Compte::where('iban', $this->iban)
            ->where('association_id', $this->association->id)
            ->firstOrFail();

        // Catégorie recette + sous-catégorie 706 + Compte 706
        $categorie = Categorie::factory()->recette()->create([
            'association_id' => $this->association->id,
            'nom' => 'Prestations',
        ]);
        $this->sc706 = SousCategorie::create([
            'association_id' => $this->association->id,
            'categorie_id' => $categorie->id,
            'nom' => 'Cotisations',
            'code_cerfa' => '706',
        ]);
        $this->compte706 = Compte::firstOrCreate(
            ['association_id' => $this->association->id, 'numero_pcg' => '706'],
            [
                'intitule' => 'Cotisations et adhésions',
                'classe' => 7,
                'lettrable' => false,
                'actif' => true,
                'est_systeme' => false,
                'pour_inscriptions' => false,
            ]
        );

        // Catégorie dépense + sous-catégorie 606 + Compte 606
        $categorieDep = Categorie::factory()->depense()->create([
            'association_id' => $this->association->id,
            'nom' => 'Charges diverses',
        ]);
        $this->sc606 = SousCategorie::create([
            'association_id' => $this->association->id,
            'categorie_id' => $categorieDep->id,
            'nom' => 'Achats fournitures',
            'code_cerfa' => '606',
        ]);
        $this->compte606 = Compte::firstOrCreate(
            ['association_id' => $this->association->id, 'numero_pcg' => '606'],
            [
                'intitule' => 'Achats non stockés de matières et fournitures',
                'classe' => 6,
                'lettrable' => false,
                'actif' => true,
                'est_systeme' => false,
                'pour_inscriptions' => false,
            ]
        );
    }
}
