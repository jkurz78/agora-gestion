<?php

declare(strict_types=1);

use App\Enums\CategorieEmail;
use App\Models\Association;
use App\Models\EmailLog;
use App\Models\Tiers;
use App\Services\Email\EmailLogStorageService;
use App\Models\User;
use App\Tenant\TenantContext;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Facades\Storage;

/**
 * Minimal Mailable stub for tests.
 * Returns a predictable subject and a rendered HTML body.
 */
final class FakeMailable extends Mailable
{
    public function __construct(
        private readonly string $mailSubject = 'Test Subject',
        private readonly string $body = 'Test <strong>body</strong>',
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->mailSubject);
    }

    public function content(): Content
    {
        // Build a trivial inline view so render() works without a real Blade view file.
        return new Content(htmlString: $this->body);
    }

    public function attachments(): array
    {
        return [];
    }
}

beforeEach(function (): void {
    Storage::fake('local');
    // Global beforeEach in Pest.php already boots a default Association.
    // We just grab it from TenantContext.
    $this->service = app(EmailLogStorageService::class);
});

// ---------------------------------------------------------------------------
// a) logSent avec PDF persiste le fichier au bon chemin
// ---------------------------------------------------------------------------

it('logSent avec PDF persiste le fichier sur le disque local', function (): void {
    $tiers = Tiers::factory()->create();
    $mail = new FakeMailable('Sujet PDF', 'Corps <b>test</b>');

    $emailLog = $this->service->logSent(
        mail: $mail,
        tiers: $tiers,
        categorie: CategorieEmail::Document,
        destinataireEmail: 'a@b.com',
        pdfContent: 'PDFCONTENT',
        pdfFilename: 'devis.pdf',
    );

    expect($emailLog)->toBeInstanceOf(EmailLog::class)
        ->and($emailLog->objet)->toBe('Sujet PDF')
        ->and($emailLog->corps_html)->toContain('test')
        ->and($emailLog->statut)->toBe('envoye')
        ->and($emailLog->attachment_path)->not->toBeNull();

    $path = $emailLog->attachment_path;
    Storage::disk('local')->assertExists($path);
    expect(Storage::disk('local')->get($path))->toBe('PDFCONTENT');
    expect($path)->toContain('email_attachments/')
        ->and($path)->toContain('devis.pdf');
});

// ---------------------------------------------------------------------------
// b) logSent sans PDF — pas de fichier, corps_html rempli
// ---------------------------------------------------------------------------

it('logSent sans PDF ne crée pas de fichier et remplit corps_html', function (): void {
    $tiers = Tiers::factory()->create();
    $mail = new FakeMailable('Sujet sans PDF', 'Corps sans PJ');

    $emailLog = $this->service->logSent(
        mail: $mail,
        tiers: $tiers,
        categorie: CategorieEmail::Message,
        destinataireEmail: 'b@c.com',
    );

    expect($emailLog->attachment_path)->toBeNull()
        ->and($emailLog->corps_html)->toContain('Corps sans PJ')
        ->and($emailLog->objet)->toBe('Sujet sans PDF')
        ->and($emailLog->statut)->toBe('envoye');

    Storage::disk('local')->assertDirectoryEmpty('');
});

// ---------------------------------------------------------------------------
// c) Path inclut bien association_id courant
// ---------------------------------------------------------------------------

it('le chemin du PDF contient l\'association_id courant', function (): void {
    // Boot a specific association (overrides the global default one)
    $assoc = Association::factory()->create();
    TenantContext::boot($assoc);

    $tiers = Tiers::factory()->create();
    $mail = new FakeMailable();

    $emailLog = $this->service->logSent(
        mail: $mail,
        tiers: $tiers,
        categorie: CategorieEmail::Document,
        destinataireEmail: 'c@d.com',
        pdfContent: 'PDF',
        pdfFilename: 'facture.pdf',
    );

    expect($emailLog->attachment_path)
        ->toStartWith("associations/{$assoc->id}/email_attachments/");
});

// ---------------------------------------------------------------------------
// d) Filename sanitization (no path traversal, weird chars stripped)
// ---------------------------------------------------------------------------

it('sanitize le nom de fichier et empêche le path traversal', function (): void {
    $tiers = Tiers::factory()->create();
    $mail = new FakeMailable();

    $emailLog = $this->service->logSent(
        mail: $mail,
        tiers: $tiers,
        categorie: CategorieEmail::Document,
        destinataireEmail: 'd@e.com',
        pdfContent: 'PDF',
        pdfFilename: '../../../etc/passwd.pdf',
    );

    $path = $emailLog->attachment_path;
    // Must stay under email_attachments/
    expect($path)->toContain('email_attachments/');
    // Must not contain ..
    expect($path)->not->toContain('..');
    // Must not be able to escape the associations/{id}/email_attachments/ prefix
    expect($path)->toMatch('/^associations\/\d+\/email_attachments\//');
});

// ---------------------------------------------------------------------------
// e) logError — pas de disk write, statut = erreur
// ---------------------------------------------------------------------------

it('logError crée un EmailLog erreur sans écrire sur le disque', function (): void {
    $tiers = Tiers::factory()->create();

    $emailLog = $this->service->logError(
        tiers: $tiers,
        categorie: CategorieEmail::Document,
        destinataireEmail: 'e@f.com',
        objetFallback: 'Fallback subject',
        erreurMessage: 'Boom',
    );

    expect($emailLog)->toBeInstanceOf(EmailLog::class)
        ->and($emailLog->statut)->toBe('erreur')
        ->and($emailLog->erreur_message)->toBe('Boom')
        ->and($emailLog->objet)->toBe('Fallback subject')
        ->and($emailLog->attachment_path)->toBeNull()
        ->and($emailLog->corps_html)->toBeNull();

    Storage::disk('local')->assertDirectoryEmpty('');
});

// ---------------------------------------------------------------------------
// f) extra fields are merged into EmailLog
// ---------------------------------------------------------------------------

// ---------------------------------------------------------------------------
// g) envoye_par auto-rempli depuis Auth::id() (succès ET erreur)
// ---------------------------------------------------------------------------

it('logSent auto-remplit envoye_par avec Auth::id() quand authentifié', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    $tiers = Tiers::factory()->create();
    $mail = new FakeMailable();

    $emailLog = $this->service->logSent(
        mail: $mail,
        tiers: $tiers,
        categorie: CategorieEmail::Document,
        destinataireEmail: 'g@h.com',
    );

    expect((int) $emailLog->envoye_par)->toBe((int) $user->id);
});

it('logError auto-remplit envoye_par avec Auth::id() quand authentifié', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    $tiers = Tiers::factory()->create();

    $emailLog = $this->service->logError(
        tiers: $tiers,
        categorie: CategorieEmail::Document,
        destinataireEmail: 'h@i.com',
        objetFallback: 'Sujet',
        erreurMessage: 'Boom',
    );

    expect((int) $emailLog->envoye_par)->toBe((int) $user->id);
});

it('logSent laisse envoye_par null quand aucun utilisateur authentifié', function (): void {
    $tiers = Tiers::factory()->create();
    $mail = new FakeMailable();

    $emailLog = $this->service->logSent(
        mail: $mail,
        tiers: $tiers,
        categorie: CategorieEmail::Document,
        destinataireEmail: 'i@j.com',
    );

    expect($emailLog->envoye_par)->toBeNull();
});

// ---------------------------------------------------------------------------
// h) extra fields are merged into EmailLog
// ---------------------------------------------------------------------------

it('les champs extra sont persistés dans l\'EmailLog', function (): void {
    $tiers = Tiers::factory()->create();
    $mail = new FakeMailable();

    $emailLog = $this->service->logSent(
        mail: $mail,
        tiers: $tiers,
        categorie: CategorieEmail::Communication,
        destinataireEmail: 'f@g.com',
        extra: [
            'campagne_id' => null,   // nullable FK — pass null to avoid constraint issues
            'tracking_token' => 'abc123token',
        ],
    );

    expect($emailLog->tracking_token)->toBe('abc123token')
        ->and($emailLog->campagne_id)->toBeNull();
});
