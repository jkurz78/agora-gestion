<?php

declare(strict_types=1);

use App\Mail\DevisLibreMail;
use App\Models\Association;
use App\Models\Devis;
use App\Models\DevisLigne;
use App\Models\EmailLog;
use App\Models\Tiers;
use App\Models\User;
use App\Services\DevisService;
use App\Tenant\TenantContext;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Mail::fake();
    Storage::fake('local');

    $this->association = Association::factory()->create([
        'devis_validite_jours' => 30,
        'nom' => 'Association Test',
        'facture_mentions_legales' => 'TVA non applicable, art. 261-7-1° du CGI',
    ]);
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    $this->actingAs($this->user);

    $this->tiers = Tiers::factory()->create([
        'nom' => 'ACME',
        'prenom' => null,
        'email' => 'acme@example.com',
    ]);
    $this->service = app(DevisService::class);
});

afterEach(function () {
    TenantContext::clear();
    Carbon::setTestNow();
});

// ─── Helpers ─────────────────────────────────────────────────────────────────

function devisValideAvecLigne(Tiers $tiers): Devis
{
    $devis = Devis::factory()->valide()->create([
        'tiers_id' => $tiers->id,
        'montant_total' => 1200.00,
    ]);

    DevisLigne::factory()->create([
        'devis_id' => $devis->id,
        'libelle' => 'Prestation conseil',
        'prix_unitaire' => 1200.00,
        'quantite' => 1.0,
        'montant' => 1200.00,
        'ordre' => 1,
    ]);

    return $devis;
}

// ─── Guard 1 : brouillon interdit ─────────────────────────────────────────────

it('envoyerEmail refuse un devis brouillon avec RuntimeException', function () {
    $devis = Devis::factory()->brouillon()->create([
        'tiers_id' => $this->tiers->id,
    ]);

    DevisLigne::factory()->create([
        'devis_id' => $devis->id,
        'libelle' => 'Ligne test',
        'prix_unitaire' => 100.00,
        'quantite' => 1.0,
        'montant' => 100.00,
        'ordre' => 1,
    ]);

    expect(fn () => $this->service->envoyerEmail($devis, 'Sujet test', '<p>Corps</p>'))
        ->toThrow(RuntimeException::class, 'brouillon');
});

// ─── Guard 2 : aucune ligne avec montant ──────────────────────────────────────

it('envoyerEmail refuse si aucune ligne avec montant > 0', function () {
    $devis = Devis::factory()->valide()->create([
        'tiers_id' => $this->tiers->id,
        'montant_total' => 0.00,
    ]);

    // Aucune ligne créée
    expect(fn () => $this->service->envoyerEmail($devis, 'Sujet', '<p>Corps</p>'))
        ->toThrow(RuntimeException::class, 'montant');
});

// ─── Guard 3 : tiers sans email ───────────────────────────────────────────────

it('envoyerEmail refuse si le tiers n\'a pas d\'email', function () {
    $tiersSansEmail = Tiers::factory()->create([
        'nom' => 'Sans Email',
        'email' => null,
    ]);

    $devis = Devis::factory()->valide()->create([
        'tiers_id' => $tiersSansEmail->id,
        'montant_total' => 500.00,
    ]);

    DevisLigne::factory()->create([
        'devis_id' => $devis->id,
        'libelle' => 'Prestation',
        'prix_unitaire' => 500.00,
        'quantite' => 1.0,
        'montant' => 500.00,
        'ordre' => 1,
    ]);

    expect(fn () => $this->service->envoyerEmail($devis, 'Sujet', '<p>Corps</p>'))
        ->toThrow(RuntimeException::class, 'email');
});

// ─── Happy path : email envoyé au tiers ───────────────────────────────────────

it('envoyerEmail envoie DevisLibreMail à l\'adresse du tiers', function () {
    $devis = devisValideAvecLigne($this->tiers);

    $this->service->envoyerEmail($devis, 'Votre devis D-2026-001', '<p>Bonjour, veuillez trouver votre devis.</p>');

    Mail::assertSent(DevisLibreMail::class, fn (DevisLibreMail $m) => $m->hasTo('acme@example.com'));
});

// ─── Mailable porte la bonne pièce jointe ─────────────────────────────────────

it('envoyerEmail joint un fichier PDF nommé d\'après le numéro du devis', function () {
    $devis = devisValideAvecLigne($this->tiers);

    $this->service->envoyerEmail($devis, 'Votre devis', '<p>Corps</p>');

    Mail::assertSent(DevisLibreMail::class, function (DevisLibreMail $m): bool {
        $attachments = $m->attachments();
        if (count($attachments) === 0) {
            return false;
        }
        // Le nom de la PJ doit commencer par "devis-" et finir par ".pdf"
        foreach ($attachments as $attachment) {
            $props = $attachment->as ?? '';
            if (str_starts_with($props, 'devis-') && str_ends_with($props, '.pdf')) {
                return true;
            }
        }

        return false;
    });
});

// ─── Log email_logs créé ──────────────────────────────────────────────────────

it('envoyerEmail crée une entrée email_logs avec tiers_id, sujet et attachment_path', function () {
    $devis = devisValideAvecLigne($this->tiers);

    $sujet = 'Votre devis D-2026-005';

    $this->service->envoyerEmail($devis, $sujet, '<p>Corps</p>');

    $log = EmailLog::where('tiers_id', $this->tiers->id)->latest()->first();

    expect($log)->not->toBeNull();
    expect($log->tiers_id)->toBe((int) $this->tiers->id);
    expect($log->objet)->toBe($sujet);
    expect($log->attachment_path)->not->toBeNull()->toBeString()->not->toBeEmpty();
    expect($log->statut)->toBe('envoye');
});
