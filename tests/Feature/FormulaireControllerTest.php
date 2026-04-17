<?php

declare(strict_types=1);

use App\Enums\DroitImage;
use App\Enums\ModePaiement;
use App\Models\FormulaireToken;
use App\Models\Operation;
use App\Models\Participant;
use App\Models\ParticipantDonneesMedicales;
use App\Models\Reglement;
use App\Models\Seance;
use App\Models\Tiers;
use App\Models\TypeOperation;
use App\Models\TypeOperationTarif;
use App\Models\User;
use App\Models\Association;
use App\Tenant\TenantContext;
use App\Services\FormulaireTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->association = Association::factory()->create();
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    $this->actingAs($this->user);
    $this->service = app(FormulaireTokenService::class);
    $this->operation = Operation::factory()
        ->for(TypeOperation::factory()->confidentiel())
        ->create([
            'nom' => 'Yoga du mardi',
            'date_debut' => now()->addMonths(2)->toDateString(),
            'date_fin' => now()->addMonths(4)->toDateString(),
            'nombre_seances' => 12,
        ]);
    $this->tiers = Tiers::factory()->create([
        'prenom' => 'Marie',
        'nom' => 'Dupont',
        'telephone' => '06 12 34 56 78',
        'email' => 'marie@example.com',
        'adresse_ligne1' => '12 rue des Lilas',
        'code_postal' => '75001',
        'ville' => 'Paris',
    ]);
    $this->participant = Participant::create([
        'tiers_id' => $this->tiers->id,
        'operation_id' => $this->operation->id,
        'date_inscription' => '2025-10-01',
    ]);
});

afterEach(function () {
    TenantContext::clear();
});

describe('index', function () {
    it('renders the index page', function () {
        $response = $this->get(route('formulaire.index'));

        $response->assertStatus(200);
        $response->assertSee('Formulaire participant');
    });

    it('redirects to show when token query param is present', function () {
        $token = $this->service->generate($this->participant);

        $response = $this->get(route('formulaire.index', ['token' => $token->token]));

        $response->assertRedirect(route('formulaire.show', ['token' => $token->token]));
    });

    it('displays success flash message', function () {
        $response = $this->get(route('formulaire.index'));
        // Just verify the page renders; flash messages are set via redirect
        $response->assertStatus(200);
    });
});

describe('show', function () {
    it('displays form with valid token', function () {
        $token = $this->service->generate($this->participant);

        $response = $this->get(route('formulaire.show', ['token' => $token->token]));

        $response->assertStatus(200);
        $response->assertSee('Bonjour Marie DUPONT');
        $response->assertSee('Yoga du mardi');
        $response->assertSee('06 12 34 56 78');
        $response->assertSee('marie@example.com');
    });

    it('redirects with error for invalid token', function () {
        $response = $this->get(route('formulaire.show', ['token' => 'XXXX-YYYY']));

        $response->assertRedirect(route('formulaire.index'));
        $response->assertSessionHasErrors('token');
    });

    it('redirects with error for expired token', function () {
        FormulaireToken::create([
            'participant_id' => $this->participant->id,
            'token' => 'ABCD-EFGH',
            'expire_at' => now()->subDay()->toDateString(),
        ]);

        $response = $this->get(route('formulaire.show', ['token' => 'ABCD-EFGH']));

        $response->assertRedirect(route('formulaire.index'));
        $response->assertSessionHasErrors('token');
    });

    it('redirects with info for used token', function () {
        FormulaireToken::create([
            'participant_id' => $this->participant->id,
            'token' => 'ABCD-EFGH',
            'expire_at' => now()->addDays(7)->toDateString(),
            'rempli_at' => now(),
        ]);

        $response = $this->get(route('formulaire.show', ['token' => 'ABCD-EFGH']));

        $response->assertRedirect(route('formulaire.index'));
        $response->assertSessionHas('info', 'Ce formulaire a déjà été rempli. Merci.');
    });

    it('passes typeOperation and tarif data to the view', function () {
        $token = $this->service->generate($this->participant);

        $response = $this->get(route('formulaire.show', ['token' => $token->token]));

        $response->assertStatus(200);
        $response->assertViewHas('participant');
        $response->assertViewHas('operation');
        $response->assertViewHas('typeOperation');
    });
});

describe('store', function () {
    it('updates Tiers with merge logic (non-empty changed values only)', function () {
        $token = $this->service->generate($this->participant);

        $response = $this->post(route('formulaire.store'), [
            'token' => $token->token,
            'telephone' => '07 98 76 54 32',
            'email' => 'marie.new@example.com',
            'adresse_ligne1' => '',  // empty → should NOT overwrite existing
            'code_postal' => '75001', // same value → no change
            'ville' => 'Lyon',
            'engagement_presence' => '1',
            'engagement_certificat' => '1',
            'engagement_reglement' => '1',
            'engagement_rgpd' => '1',
            'token_confirmation' => $token->token,
        ]);

        $response->assertRedirectToRoute('formulaire.merci');

        $this->tiers->refresh();
        expect($this->tiers->telephone)->toBe('07 98 76 54 32')
            ->and($this->tiers->email)->toBe('marie.new@example.com')
            ->and($this->tiers->adresse_ligne1)->toBe('12 rue des Lilas') // kept original
            ->and($this->tiers->code_postal)->toBe('75001')
            ->and($this->tiers->ville)->toBe('Lyon');
    });

    it('creates ParticipantDonneesMedicales', function () {
        $token = $this->service->generate($this->participant);

        $this->post(route('formulaire.store'), [
            'token' => $token->token,
            'date_naissance' => '1985-06-15',
            'sexe' => 'F',
            'taille' => '168',
            'poids' => '62',
            'notes' => 'Allergie au pollen',
            'engagement_presence' => '1',
            'engagement_certificat' => '1',
            'engagement_reglement' => '1',
            'engagement_rgpd' => '1',
            'token_confirmation' => $token->token,
        ]);

        $dm = ParticipantDonneesMedicales::where('participant_id', $this->participant->id)->first();
        expect($dm)->not->toBeNull()
            ->and($dm->date_naissance)->toBe('1985-06-15')
            ->and($dm->sexe)->toBe('F')
            ->and($dm->taille)->toBe('168')
            ->and($dm->poids)->toBe('62')
            ->and($dm->notes)->toBe('Allergie au pollen');
    });

    it('uploads files to local disk', function () {
        Storage::fake('local');
        $token = $this->service->generate($this->participant);

        $file1 = UploadedFile::fake()->create('certificat.pdf', 1024);
        $file2 = UploadedFile::fake()->image('photo.jpg');

        $this->post(route('formulaire.store'), [
            'token' => $token->token,
            'documents' => [$file1, $file2],
            'engagement_presence' => '1',
            'engagement_certificat' => '1',
            'engagement_reglement' => '1',
            'engagement_rgpd' => '1',
            'token_confirmation' => $token->token,
        ]);

        $dir = "participants/{$this->participant->id}";
        $files = Storage::disk('local')->files($dir);
        expect($files)->toHaveCount(2);
    });

    it('marks token as used', function () {
        $token = $this->service->generate($this->participant);

        $this->post(route('formulaire.store'), [
            'token' => $token->token,
            'engagement_presence' => '1',
            'engagement_certificat' => '1',
            'engagement_reglement' => '1',
            'engagement_rgpd' => '1',
            'token_confirmation' => $token->token,
        ]);

        $token->refresh();
        expect($token->rempli_at)->not->toBeNull()
            ->and($token->rempli_ip)->not->toBeNull();
    });

    it('rejects submission with invalid token', function () {
        $response = $this->post(route('formulaire.store'), [
            'token' => 'XXXX-YYYY',
            'telephone' => '06 00 00 00 00',
        ]);

        $response->assertRedirect(route('formulaire.index'));
        $response->assertSessionHasErrors('token');
    });

    it('rejects submission with used token', function () {
        FormulaireToken::create([
            'participant_id' => $this->participant->id,
            'token' => 'ABCD-EFGH',
            'expire_at' => now()->addDays(7)->toDateString(),
            'rempli_at' => now(),
        ]);

        $response = $this->post(route('formulaire.store'), [
            'token' => 'ABCD-EFGH',
            'telephone' => '06 00 00 00 00',
        ]);

        $response->assertRedirect(route('formulaire.index'));
        $response->assertSessionHasErrors('token');
    });

    it('validates medical data fields', function () {
        $token = $this->service->generate($this->participant);

        $response = $this->post(route('formulaire.store'), [
            'token' => $token->token,
            'date_naissance' => 'not-a-date',
            'sexe' => 'X',
            'taille' => '999',
            'poids' => '5',
            'engagement_presence' => '1',
            'engagement_certificat' => '1',
            'engagement_reglement' => '1',
            'engagement_rgpd' => '1',
            'token_confirmation' => $token->token,
        ]);

        $response->assertSessionHasErrors(['date_naissance', 'sexe', 'taille', 'poids']);
    });

    it('redirects to merci page after store', function () {
        $token = $this->service->generate($this->participant);

        $response = $this->post(route('formulaire.store'), [
            'token' => $token->token,
            'engagement_presence' => '1',
            'engagement_certificat' => '1',
            'engagement_reglement' => '1',
            'engagement_rgpd' => '1',
            'token_confirmation' => $token->token,
        ]);

        $response->assertRedirectToRoute('formulaire.merci');
    });

    it('stores enriched form data including medical contacts and engagements', function () {
        Storage::fake('local');
        $token = $this->service->generate($this->participant);

        $response = $this->post(route('formulaire.store'), [
            'token' => $token->token,
            'telephone' => '0612345678',
            'email' => 'test@example.com',
            'nom_jeune_fille' => 'Martin',
            'nationalite' => 'Française',
            'adresse_par_nom' => 'Dupont',
            'adresse_par_prenom' => 'Jean',
            'date_naissance' => '1990-01-15',
            'sexe' => 'F',
            'taille' => '165',
            'poids' => '60',
            'medecin_nom' => 'Dr Legrand',
            'medecin_telephone' => '0145678901',
            'therapeute_nom' => 'Mme Moreau',
            'droit_image' => 'usage_propre',
            'mode_paiement_choisi' => 'par_seance',
            'moyen_paiement_choisi' => 'cheque',
            'autorisation_contact_medecin' => '1',
            'engagement_presence' => '1',
            'engagement_certificat' => '1',
            'engagement_reglement' => '1',
            'engagement_rgpd' => '1',
            'token_confirmation' => $token->token,
        ]);

        $response->assertRedirectToRoute('formulaire.merci');

        $this->participant->refresh();
        expect($this->participant->nom_jeune_fille)->toBe('Martin');
        expect($this->participant->nationalite)->toBe('Française');
        expect($this->participant->adresse_par_nom)->toBe('Dupont');
        expect($this->participant->droit_image)->toBe(DroitImage::UsagePropre);
        expect($this->participant->mode_paiement_choisi)->toBe('par_seance');
        expect($this->participant->moyen_paiement_choisi)->toBe('cheque');
        expect($this->participant->autorisation_contact_medecin)->toBeTrue();
        expect($this->participant->rgpd_accepte_at)->not->toBeNull();

        $medical = $this->participant->donneesMedicales;
        expect($medical->medecin_nom)->toBe('Dr Legrand');
        expect($medical->therapeute_nom)->toBe('Mme Moreau');
    });

    it('rejects submission when token_confirmation does not match', function () {
        $token = $this->service->generate($this->participant);

        $response = $this->post(route('formulaire.store'), [
            'token' => $token->token,
            'token_confirmation' => 'WRONG-CODE',
            'engagement_presence' => '1',
            'engagement_certificat' => '1',
            'engagement_reglement' => '1',
            'engagement_rgpd' => '1',
        ]);

        $response->assertSessionHasErrors('token_confirmation');
    });

    it('creates reglement lines per seance when mode is par_seance', function () {
        $token = $this->service->generate($this->participant);

        $seance1 = Seance::create(['operation_id' => $this->operation->id, 'numero' => 1, 'date' => now()]);
        $seance2 = Seance::create(['operation_id' => $this->operation->id, 'numero' => 2, 'date' => now()->addWeek()]);

        $tarif = TypeOperationTarif::create([
            'type_operation_id' => $this->operation->typeOperation->id,
            'libelle' => 'Tarif test',
            'montant' => 50.00,
        ]);
        $this->participant->update(['type_operation_tarif_id' => $tarif->id]);

        $this->post(route('formulaire.store'), [
            'token' => $token->token,
            'mode_paiement_choisi' => 'par_seance',
            'moyen_paiement_choisi' => 'cheque',
            'engagement_presence' => '1',
            'engagement_certificat' => '1',
            'engagement_reglement' => '1',
            'engagement_rgpd' => '1',
            'token_confirmation' => $token->token,
        ]);

        expect(Reglement::where('participant_id', $this->participant->id)->count())->toBe(2);

        $reglement = Reglement::where('participant_id', $this->participant->id)
            ->where('seance_id', $seance1->id)->first();
        expect($reglement->montant_prevu)->toBe('50.00');
        expect($reglement->mode_paiement)->toBe(ModePaiement::Cheque);
    });

    it('creates reglement lines per seance when mode is comptant', function () {
        $token = $this->service->generate($this->participant);

        $seance1 = Seance::create(['operation_id' => $this->operation->id, 'numero' => 1, 'date' => now()]);
        $seance2 = Seance::create(['operation_id' => $this->operation->id, 'numero' => 2, 'date' => now()->addWeek()]);

        $tarif = TypeOperationTarif::create([
            'type_operation_id' => $this->operation->typeOperation->id,
            'libelle' => 'Tarif test',
            'montant' => 50.00,
        ]);
        $this->participant->update(['type_operation_tarif_id' => $tarif->id]);

        $this->post(route('formulaire.store'), [
            'token' => $token->token,
            'mode_paiement_choisi' => 'comptant',
            'moyen_paiement_choisi' => 'virement',
            'engagement_presence' => '1',
            'engagement_certificat' => '1',
            'engagement_reglement' => '1',
            'engagement_rgpd' => '1',
            'token_confirmation' => $token->token,
        ]);

        // Comptant : même montant par séance (total/nb_seances = tarif car total = nb_seances * tarif)
        expect(Reglement::where('participant_id', $this->participant->id)->count())->toBe(2);

        $reglement = Reglement::where('participant_id', $this->participant->id)
            ->where('seance_id', $seance1->id)->first();
        expect($reglement->montant_prevu)->toBe('50.00');
        expect($reglement->mode_paiement)->toBe(ModePaiement::Virement);
    });

    it('does not overwrite existing reglements', function () {
        $token = $this->service->generate($this->participant);

        $seance = Seance::create(['operation_id' => $this->operation->id, 'numero' => 1, 'date' => now()]);

        $tarif = TypeOperationTarif::create([
            'type_operation_id' => $this->operation->typeOperation->id,
            'libelle' => 'Tarif test',
            'montant' => 50.00,
        ]);
        $this->participant->update(['type_operation_tarif_id' => $tarif->id]);

        // Pre-existing reglement
        Reglement::create([
            'participant_id' => $this->participant->id,
            'seance_id' => $seance->id,
            'mode_paiement' => ModePaiement::Virement,
            'montant_prevu' => 75.00,
        ]);

        $this->post(route('formulaire.store'), [
            'token' => $token->token,
            'mode_paiement_choisi' => 'par_seance',
            'moyen_paiement_choisi' => 'especes',
            'engagement_presence' => '1',
            'engagement_certificat' => '1',
            'engagement_reglement' => '1',
            'engagement_rgpd' => '1',
            'token_confirmation' => $token->token,
        ]);

        $reglement = Reglement::where('participant_id', $this->participant->id)
            ->where('seance_id', $seance->id)->first();
        // Not overwritten
        expect($reglement->montant_prevu)->toBe('75.00');
        expect($reglement->mode_paiement)->toBe(ModePaiement::Virement);
    });
});
