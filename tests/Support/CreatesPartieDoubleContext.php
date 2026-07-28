<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Association;
use App\Models\Compte;
use App\Models\CompteBancaire;
use App\Models\User;
use App\Services\Compta\Migrations\BancairesSeeder;
use App\Services\Compta\Migrations\SystemeSeeder;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Config;

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
 * - compte706 (produit de classe 7)
 */
trait CreatesPartieDoubleContext
{
    /**
     * Établit le contexte partie double commun à tous les tests PD Feature.
     *
     * - Crée une association + un user admin
     * - Boote TenantContext + session
     * - Seed comptes système (411, 401, 5112, 530)
     * - Crée un CompteBancaire avec IBAN connu + Compte 512X via BancairesSeeder
     * - Crée les comptes de ventilation 706 et 606
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

        // Comptes système : 411, 401, 5112, 530
        SystemeSeeder::seed();

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

        // Compte de produit 706
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

        // Compte de charge 606
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
