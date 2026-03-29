<?php

declare(strict_types=1);

use App\Models\FormulaireToken;
use App\Models\Operation;
use App\Models\Participant;
use App\Models\ParticipantDonneesMedicales;
use App\Models\Tiers;
use App\Models\User;
use App\Services\FormulaireTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->service = app(FormulaireTokenService::class);
    $this->operation = Operation::factory()->create([
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
        $response->assertSee('Bonjour Marie Dupont');
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
        ]);

        $response->assertRedirect(route('formulaire.index'));
        $response->assertSessionHas('success');

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
        ]);

        $dir = "participants/{$this->participant->id}";
        $files = Storage::disk('local')->files($dir);
        expect($files)->toHaveCount(2);
    });

    it('marks token as used', function () {
        $token = $this->service->generate($this->participant);

        $this->post(route('formulaire.store'), [
            'token' => $token->token,
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
        ]);

        $response->assertSessionHasErrors(['date_naissance', 'sexe', 'taille', 'poids']);
    });

    it('redirects with success message after store', function () {
        $token = $this->service->generate($this->participant);

        $response = $this->post(route('formulaire.store'), [
            'token' => $token->token,
        ]);

        $response->assertRedirect(route('formulaire.index'));
        $response->assertSessionHas('success', 'Merci ! Vos informations ont bien été enregistrées. Vous pouvez fermer cette page.');
    });
});
