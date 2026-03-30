# v2.3.1 — email_logs + Page participant dédiée — Plan d'implémentation

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ajouter la traçabilité des emails envoyés (table `email_logs`) et transformer le panneau d'édition participant en page imbriquée avec onglet Historique.

**Architecture:** Deux livrables indépendants. Le livrable 1 (email_logs) crée le modèle et câble l'enregistrement dans l'envoi existant. Le livrable 2 crée un nouveau composant Livewire `ParticipantShow` qui remplace le panneau plein écran d'édition dans `ParticipantTable`, piloté par un paramètre URL `participant` dans `GestionOperations`.

**Tech Stack:** Laravel 11, Livewire 4, Bootstrap 5, Alpine.js, Pest PHP

**Spec:** `docs/superpowers/specs/2026-03-30-v231-email-logs-page-participant-design.md`

---

## Fichiers concernés

### Livrable 1 — email_logs
| Action | Fichier | Responsabilité |
|--------|---------|----------------|
| Créer | `database/migrations/2026_03_30_200001_create_email_logs_table.php` | Migration table email_logs |
| Créer | `app/Models/EmailLog.php` | Modèle Eloquent |
| Modifier | `app/Models/Participant.php` | Ajouter relation `emailLogs()` |
| Modifier | `app/Models/Tiers.php` | Ajouter relation `emailLogs()` |
| Modifier | `app/Models/Operation.php` | Ajouter relation `emailLogs()` |
| Modifier | `app/Livewire/ParticipantTable.php:559-619` | Câbler EmailLog dans `envoyerTokenParEmail()` |
| Créer | `tests/Feature/EmailLogTest.php` | Tests du modèle et de l'intégration |

### Livrable 2 — Page participant
| Action | Fichier | Responsabilité |
|--------|---------|----------------|
| Modifier | `app/Livewire/GestionOperations.php` | Ajouter propriété `#[Url] selectedParticipantId`, logique de bascule |
| Modifier | `resources/views/livewire/gestion-operations.blade.php:140-142` | Rendu conditionnel ParticipantShow vs ParticipantTable |
| Créer | `app/Livewire/ParticipantShow.php` | Composant dédié : édition + historique |
| Créer | `resources/views/livewire/participant-show.blade.php` | Vue du composant avec onglets |
| Modifier | `app/Livewire/ParticipantTable.php` | Supprimer panneau édition, remplacer par lien navigation |
| Modifier | `resources/views/livewire/participant-table.blade.php` | Supprimer le bloc edit modal (lignes 439-951), remplacer clic nom par navigation |
| Créer | `tests/Feature/Livewire/ParticipantShowTest.php` | Tests du nouveau composant |

---

## Task 1 : Migration et modèle EmailLog

**Files:**
- Créer : `database/migrations/2026_03_30_200001_create_email_logs_table.php`
- Créer : `app/Models/EmailLog.php`
- Créer : `tests/Feature/EmailLogTest.php`

- [ ] **Step 1 : Écrire le test du modèle EmailLog**

```php
// tests/Feature/EmailLogTest.php
<?php

declare(strict_types=1);

use App\Models\EmailLog;
use App\Models\Operation;
use App\Models\Participant;
use App\Models\Tiers;
use App\Models\User;

it('can create an email log with all fields', function () {
    $user = User::factory()->create();
    $tiers = Tiers::factory()->create();
    $operation = Operation::factory()->create();
    $participant = Participant::create([
        'tiers_id' => $tiers->id,
        'operation_id' => $operation->id,
        'date_inscription' => '2026-01-15',
    ]);

    $log = EmailLog::create([
        'tiers_id' => $tiers->id,
        'participant_id' => $participant->id,
        'operation_id' => $operation->id,
        'categorie' => 'formulaire',
        'destinataire_email' => 'test@example.com',
        'destinataire_nom' => 'Dupont Marie',
        'objet' => 'Formulaire à compléter',
        'statut' => 'envoye',
        'envoye_par' => $user->id,
    ]);

    expect($log)->toBeInstanceOf(EmailLog::class)
        ->and($log->tiers_id)->toBe($tiers->id)
        ->and($log->participant_id)->toBe($participant->id)
        ->and($log->operation_id)->toBe($operation->id)
        ->and($log->categorie)->toBe('formulaire')
        ->and($log->statut)->toBe('envoye');
});

it('has correct relationships', function () {
    $user = User::factory()->create();
    $tiers = Tiers::factory()->create();
    $operation = Operation::factory()->create();
    $participant = Participant::create([
        'tiers_id' => $tiers->id,
        'operation_id' => $operation->id,
        'date_inscription' => '2026-01-15',
    ]);

    $log = EmailLog::create([
        'tiers_id' => $tiers->id,
        'participant_id' => $participant->id,
        'operation_id' => $operation->id,
        'categorie' => 'formulaire',
        'destinataire_email' => 'test@example.com',
        'objet' => 'Test',
        'statut' => 'envoye',
        'envoye_par' => $user->id,
    ]);

    expect($log->tiers)->toBeInstanceOf(Tiers::class)
        ->and($log->participant)->toBeInstanceOf(Participant::class)
        ->and($log->operation)->toBeInstanceOf(Operation::class)
        ->and($log->envoyePar)->toBeInstanceOf(User::class);
});

it('allows nullable foreign keys', function () {
    $log = EmailLog::create([
        'categorie' => 'facture',
        'destinataire_email' => 'fournisseur@example.com',
        'objet' => 'Facture #123',
        'statut' => 'envoye',
    ]);

    expect($log->tiers_id)->toBeNull()
        ->and($log->participant_id)->toBeNull()
        ->and($log->operation_id)->toBeNull()
        ->and($log->envoye_par)->toBeNull();
});

it('can store error status with message', function () {
    $log = EmailLog::create([
        'categorie' => 'formulaire',
        'destinataire_email' => 'bad@example.com',
        'objet' => 'Test',
        'statut' => 'erreur',
        'erreur_message' => 'Connection refused',
    ]);

    expect($log->statut)->toBe('erreur')
        ->and($log->erreur_message)->toBe('Connection refused');
});

it('has inverse relationship on Participant', function () {
    $tiers = Tiers::factory()->create();
    $operation = Operation::factory()->create();
    $participant = Participant::create([
        'tiers_id' => $tiers->id,
        'operation_id' => $operation->id,
        'date_inscription' => '2026-01-15',
    ]);

    EmailLog::create([
        'participant_id' => $participant->id,
        'categorie' => 'formulaire',
        'destinataire_email' => 'test@example.com',
        'objet' => 'Test',
        'statut' => 'envoye',
    ]);

    expect($participant->emailLogs)->toHaveCount(1);
});

it('has inverse relationship on Tiers', function () {
    $tiers = Tiers::factory()->create();

    EmailLog::create([
        'tiers_id' => $tiers->id,
        'categorie' => 'facture',
        'destinataire_email' => $tiers->email ?? 'test@test.com',
        'objet' => 'Test',
        'statut' => 'envoye',
    ]);

    expect($tiers->emailLogs)->toHaveCount(1);
});
```

- [ ] **Step 2 : Vérifier que les tests échouent**

Run: `./vendor/bin/sail test tests/Feature/EmailLogTest.php`
Expected: FAIL — table `email_logs` et modèle `EmailLog` n'existent pas.

- [ ] **Step 3 : Créer la migration**

```php
// database/migrations/2026_03_30_200001_create_email_logs_table.php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tiers_id')->nullable()->constrained('tiers')->nullOnDelete();
            $table->foreignId('participant_id')->nullable()->constrained('participants')->nullOnDelete();
            $table->foreignId('operation_id')->nullable()->constrained('operations')->nullOnDelete();
            $table->string('categorie', 30);
            $table->foreignId('email_template_id')->nullable()->constrained('email_templates')->nullOnDelete();
            $table->string('destinataire_email');
            $table->string('destinataire_nom')->nullable();
            $table->string('objet');
            $table->string('statut', 20);
            $table->text('erreur_message')->nullable();
            $table->foreignId('envoye_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_logs');
    }
};
```

- [ ] **Step 4 : Créer le modèle EmailLog**

```php
// app/Models/EmailLog.php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class EmailLog extends Model
{
    protected $fillable = [
        'tiers_id',
        'participant_id',
        'operation_id',
        'categorie',
        'email_template_id',
        'destinataire_email',
        'destinataire_nom',
        'objet',
        'statut',
        'erreur_message',
        'envoye_par',
    ];

    public function tiers(): BelongsTo
    {
        return $this->belongsTo(Tiers::class);
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(Participant::class);
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(Operation::class);
    }

    public function emailTemplate(): BelongsTo
    {
        return $this->belongsTo(EmailTemplate::class);
    }

    public function envoyePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'envoye_par');
    }
}
```

- [ ] **Step 5 : Ajouter les relations inverses**

Dans `app/Models/Participant.php`, ajouter après la relation `formulaireToken()` :

```php
public function emailLogs(): HasMany
{
    return $this->hasMany(EmailLog::class);
}
```

Dans `app/Models/Tiers.php`, ajouter après la relation `participants()` :

```php
public function emailLogs(): HasMany
{
    return $this->hasMany(EmailLog::class);
}
```

Dans `app/Models/Operation.php`, ajouter après la relation `seances()` :

```php
public function emailLogs(): HasMany
{
    return $this->hasMany(EmailLog::class);
}
```

Ne pas oublier le `use` de `EmailLog` dans chaque modèle.

- [ ] **Step 6 : Exécuter la migration et les tests**

Run: `./vendor/bin/sail artisan migrate && ./vendor/bin/sail test tests/Feature/EmailLogTest.php`
Expected: Migration OK, tous les tests passent.

- [ ] **Step 7 : Commit**

```bash
git add database/migrations/2026_03_30_200001_create_email_logs_table.php app/Models/EmailLog.php app/Models/Participant.php app/Models/Tiers.php app/Models/Operation.php tests/Feature/EmailLogTest.php
git commit -m "feat: add email_logs table and EmailLog model with relationships"
```

---

## Task 2 : Câbler EmailLog dans l'envoi de formulaire

**Files:**
- Modifier : `app/Livewire/ParticipantTable.php:559-619`
- Modifier : `tests/Feature/EmailLogTest.php`

- [ ] **Step 1 : Écrire le test d'intégration**

Ajouter dans `tests/Feature/EmailLogTest.php` :

```php
use App\Livewire\ParticipantTable;
use App\Models\EmailTemplate;
use App\Models\TypeOperation;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

it('logs email when formulaire invitation is sent successfully', function () {
    Mail::fake();
    $user = User::factory()->create();
    $this->actingAs($user);

    $typeOp = TypeOperation::factory()->create([
        'email_from' => 'asso@example.com',
        'formulaire_actif' => true,
    ]);
    $operation = Operation::factory()->create(['type_operation_id' => $typeOp->id]);
    $tiers = Tiers::factory()->create(['email' => 'participant@example.com', 'nom' => 'Dupont', 'prenom' => 'Marie']);
    $participant = Participant::create([
        'tiers_id' => $tiers->id,
        'operation_id' => $operation->id,
        'date_inscription' => '2026-01-15',
    ]);

    // Create a default template
    EmailTemplate::create([
        'categorie' => 'formulaire',
        'type_operation_id' => $typeOp->id,
        'objet' => 'Votre formulaire — {operation}',
        'corps' => '<p>Bonjour {prenom}</p>',
    ]);

    Livewire::test(ParticipantTable::class, ['operation' => $operation])
        ->call('genererToken', $participant->id)
        ->call('envoyerTokenParEmail');

    $log = EmailLog::where('participant_id', $participant->id)->first();
    expect($log)->not->toBeNull()
        ->and($log->tiers_id)->toBe($tiers->id)
        ->and($log->operation_id)->toBe($operation->id)
        ->and($log->categorie)->toBe('formulaire')
        ->and($log->destinataire_email)->toBe('participant@example.com')
        ->and($log->destinataire_nom)->toBe('Dupont Marie')
        ->and($log->statut)->toBe('envoye')
        ->and($log->envoye_par)->toBe($user->id);
});

it('logs error when email sending fails', function () {
    Mail::shouldReceive('mailer')->andThrow(new \RuntimeException('SMTP down'));
    $user = User::factory()->create();
    $this->actingAs($user);

    $typeOp = TypeOperation::factory()->create([
        'email_from' => 'asso@example.com',
        'formulaire_actif' => true,
    ]);
    $operation = Operation::factory()->create(['type_operation_id' => $typeOp->id]);
    $tiers = Tiers::factory()->create(['email' => 'test@example.com', 'nom' => 'Test', 'prenom' => 'User']);
    $participant = Participant::create([
        'tiers_id' => $tiers->id,
        'operation_id' => $operation->id,
        'date_inscription' => '2026-01-15',
    ]);

    EmailTemplate::create([
        'categorie' => 'formulaire',
        'type_operation_id' => $typeOp->id,
        'objet' => 'Formulaire',
        'corps' => '<p>Test</p>',
    ]);

    Livewire::test(ParticipantTable::class, ['operation' => $operation])
        ->call('genererToken', $participant->id)
        ->call('envoyerTokenParEmail');

    $log = EmailLog::where('participant_id', $participant->id)->first();
    expect($log)->not->toBeNull()
        ->and($log->statut)->toBe('erreur')
        ->and($log->erreur_message)->toContain('SMTP down');
});
```

- [ ] **Step 2 : Vérifier que les tests échouent**

Run: `./vendor/bin/sail test tests/Feature/EmailLogTest.php --filter="logs email"`
Expected: FAIL — aucun EmailLog n'est créé.

- [ ] **Step 3 : Modifier `envoyerTokenParEmail()`**

Dans `app/Livewire/ParticipantTable.php`, ajouter `use App\Models\EmailLog;` en haut du fichier.

Remplacer la méthode `envoyerTokenParEmail()` (lignes 559-619) par :

```php
public function envoyerTokenParEmail(): void
{
    if ($this->tokenParticipantId === null || $this->tokenUrl === null) {
        return;
    }

    $participant = Participant::with('tiers', 'operation.typeOperation')
        ->findOrFail($this->tokenParticipantId);

    $email = $participant->tiers?->email;
    if (! $email) {
        $this->tokenEmailMessage = 'Ce participant n\'a pas d\'adresse email renseignée.';
        $this->tokenEmailType = 'danger';

        return;
    }

    $typeOp = $participant->operation?->typeOperation;
    if (! $typeOp?->email_from) {
        $this->tokenEmailMessage = 'L\'adresse d\'expédition n\'est pas configurée sur le type d\'opération.';
        $this->tokenEmailType = 'danger';

        return;
    }

    // Load email template (custom for this type, or default)
    $template = EmailTemplate::where('categorie', 'formulaire')
        ->where('type_operation_id', $typeOp->id)
        ->first()
        ?? EmailTemplate::where('categorie', 'formulaire')
            ->whereNull('type_operation_id')
            ->first();

    $destinataireNom = trim(($participant->tiers->nom ?? '') . ' ' . ($participant->tiers->prenom ?? ''));

    try {
        $op = $participant->operation;
        $mail = new FormulaireInvitation(
            prenomParticipant: $participant->tiers->prenom ?? 'Participant',
            nomParticipant: $participant->tiers->nom ?? '',
            nomOperation: $op->nom,
            nomTypeOperation: $typeOp->nom,
            formulaireUrl: $this->tokenUrl,
            tokenCode: $this->tokenCode ?? '',
            dateExpiration: Carbon::parse($this->tokenExpireAt)->format('d/m/Y'),
            dateDebut: $op->date_debut?->format('d/m/Y') ?? '',
            dateFin: $op->date_fin?->format('d/m/Y') ?? '',
            nombreSeances: $op->nombre_seances !== null ? (string) $op->nombre_seances : '',
            customObjet: $template?->objet,
            customCorps: $template?->corps,
        );

        Mail::mailer()
            ->to($email)
            ->send($mail->from($typeOp->email_from, $typeOp->email_from_name ?? null));

        EmailLog::create([
            'tiers_id' => $participant->tiers_id,
            'participant_id' => $participant->id,
            'operation_id' => $participant->operation_id,
            'categorie' => 'formulaire',
            'email_template_id' => $template?->id,
            'destinataire_email' => $email,
            'destinataire_nom' => $destinataireNom !== '' ? $destinataireNom : null,
            'objet' => $mail->subject ?? $template?->objet ?? 'Formulaire',
            'statut' => 'envoye',
            'envoye_par' => Auth::id(),
        ]);

        $this->tokenEmailMessage = "Email envoyé à {$email}.";
        $this->tokenEmailType = 'success';
    } catch (\Throwable $e) {
        EmailLog::create([
            'tiers_id' => $participant->tiers_id,
            'participant_id' => $participant->id,
            'operation_id' => $participant->operation_id,
            'categorie' => 'formulaire',
            'email_template_id' => $template?->id,
            'destinataire_email' => $email,
            'destinataire_nom' => $destinataireNom !== '' ? $destinataireNom : null,
            'objet' => $template?->objet ?? 'Formulaire',
            'statut' => 'erreur',
            'erreur_message' => $e->getMessage(),
            'envoye_par' => Auth::id(),
        ]);

        $this->tokenEmailMessage = 'Erreur lors de l\'envoi : ' . $e->getMessage();
        $this->tokenEmailType = 'danger';
    }
}
```

- [ ] **Step 4 : Exécuter les tests**

Run: `./vendor/bin/sail test tests/Feature/EmailLogTest.php`
Expected: Tous les tests passent.

- [ ] **Step 5 : Lancer Pint**

Run: `./vendor/bin/sail exec laravel.test ./vendor/bin/pint`

- [ ] **Step 6 : Commit**

```bash
git add app/Livewire/ParticipantTable.php tests/Feature/EmailLogTest.php
git commit -m "feat: log email sends in email_logs table (formulaire invitation)"
```

---

## Task 3 : Composant ParticipantShow — structure et onglet Coordonnées

**Files:**
- Créer : `app/Livewire/ParticipantShow.php`
- Créer : `resources/views/livewire/participant-show.blade.php`
- Créer : `tests/Feature/Livewire/ParticipantShowTest.php`

- [ ] **Step 1 : Écrire les tests de base**

```php
// tests/Feature/Livewire/ParticipantShowTest.php
<?php

declare(strict_types=1);

use App\Livewire\ParticipantShow;
use App\Models\Operation;
use App\Models\Participant;
use App\Models\Tiers;
use App\Models\TypeOperation;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create(['peut_voir_donnees_sensibles' => true]);
    $this->actingAs($this->user);
    $this->typeOp = TypeOperation::factory()->create([
        'formulaire_parcours_therapeutique' => true,
        'formulaire_prescripteur' => true,
        'formulaire_droit_image' => true,
    ]);
    $this->operation = Operation::factory()->create(['type_operation_id' => $this->typeOp->id]);
    $this->tiers = Tiers::factory()->create(['nom' => 'Dupont', 'prenom' => 'Marie', 'email' => 'marie@test.com']);
    $this->participant = Participant::create([
        'tiers_id' => $this->tiers->id,
        'operation_id' => $this->operation->id,
        'date_inscription' => '2026-01-15',
    ]);
});

it('renders participant show with participant name', function () {
    Livewire::test(ParticipantShow::class, [
        'operation' => $this->operation,
        'participant' => $this->participant,
    ])
        ->assertOk()
        ->assertSee('Dupont')
        ->assertSee('Marie');
});

it('can save coordonnées changes', function () {
    Livewire::test(ParticipantShow::class, [
        'operation' => $this->operation,
        'participant' => $this->participant,
    ])
        ->set('editNom', 'Martin')
        ->set('editPrenom', 'Sophie')
        ->set('editEmail', 'sophie@test.com')
        ->call('save');

    $this->tiers->refresh();
    expect($this->tiers->nom)->toBe('Martin')
        ->and($this->tiers->prenom)->toBe('Sophie')
        ->and($this->tiers->email)->toBe('sophie@test.com');
});

it('shows back link to participant list', function () {
    Livewire::test(ParticipantShow::class, [
        'operation' => $this->operation,
        'participant' => $this->participant,
    ])
        ->assertSee('Retour à la liste');
});
```

- [ ] **Step 2 : Vérifier que les tests échouent**

Run: `./vendor/bin/sail test tests/Feature/Livewire/ParticipantShowTest.php`
Expected: FAIL — classe `ParticipantShow` n'existe pas.

- [ ] **Step 3 : Créer le composant Livewire ParticipantShow**

```php
// app/Livewire/ParticipantShow.php
<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\Operation;
use App\Models\Participant;
use App\Models\ParticipantDonneesMedicales;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

final class ParticipantShow extends Component
{
    public Operation $operation;

    public Participant $participant;

    public string $activeTab = 'coordonnees';

    // ── Coordonnées (Tiers) ──────────────────────────────────
    public string $editNom = '';

    public string $editPrenom = '';

    public string $editAdresse = '';

    public string $editCodePostal = '';

    public string $editVille = '';

    public string $editTelephone = '';

    public string $editEmail = '';

    public string $editDateInscription = '';

    public ?int $editReferePar = null;

    public ?int $editTypeOperationTarifId = null;

    // ── Données personnelles ─────────────────────────────────
    public string $editNomJeuneFille = '';

    public string $editNationalite = '';

    public string $editDateNaissance = '';

    public string $editSexe = '';

    public string $editTaille = '';

    public string $editPoids = '';

    // ── Contacts médicaux ────────────────────────────────────
    public string $editMedecinNom = '';

    public string $editMedecinPrenom = '';

    public string $editMedecinTelephone = '';

    public string $editMedecinEmail = '';

    public string $editMedecinAdresse = '';

    public string $editMedecinCodePostal = '';

    public string $editMedecinVille = '';

    public string $editTherapeuteNom = '';

    public string $editTherapeutePrenom = '';

    public string $editTherapeuteTelephone = '';

    public string $editTherapeuteEmail = '';

    public string $editTherapeuteAdresse = '';

    public string $editTherapeuteCodePostal = '';

    public string $editTherapeuteVille = '';

    // Tiers mapping
    public ?int $mapMedecinTiersId = null;

    public ?int $mapTherapeuteTiersId = null;

    // ── Adressé par ──────────────────────────────────────────
    public string $editAdresseParEtablissement = '';

    public string $editAdresseParNom = '';

    public string $editAdresseParPrenom = '';

    public string $editAdresseParTelephone = '';

    public string $editAdresseParEmail = '';

    public string $editAdresseParAdresse = '';

    public string $editAdresseParCodePostal = '';

    public string $editAdresseParVille = '';

    public ?int $mapAdresseParTiersId = null;

    // ── Notes ────────────────────────────────────────────────
    public string $medNotes = '';

    // ── Engagements (lecture seule) ──────────────────────────
    public ?string $editDroitImageLabel = null;

    public ?string $editModePaiement = null;

    public ?string $editMoyenPaiement = null;

    public ?bool $editAutorisationContactMedecin = null;

    public ?string $editRgpdAccepteAt = null;

    public ?string $editFormulaireRempliAt = null;

    // ── Documents ────────────────────────────────────────────
    /** @var array<int, array{name: string, size: int, url: string}> */
    public array $editDocuments = [];

    // ── State ────────────────────────────────────────────────
    public string $successMessage = '';

    public function mount(Operation $operation, Participant $participant): void
    {
        $this->operation = $operation;

        $participant->load([
            'tiers', 'donneesMedicales', 'referePar',
            'medecinTiers', 'therapeuteTiers', 'formulaireToken',
        ]);
        $this->participant = $participant;

        $this->loadParticipantData();
    }

    public function save(): void
    {
        $participant = $this->participant->load('tiers');

        // Update tiers
        $participant->tiers->update([
            'nom' => $this->editNom,
            'prenom' => $this->editPrenom,
            'adresse_ligne1' => $this->editAdresse,
            'code_postal' => $this->editCodePostal,
            'ville' => $this->editVille,
            'telephone' => $this->editTelephone,
            'email' => $this->editEmail,
        ]);

        // Update participant
        $participant->update([
            'date_inscription' => $this->editDateInscription,
            'refere_par_id' => $this->editReferePar,
            'type_operation_tarif_id' => $this->editTypeOperationTarifId,
            'nom_jeune_fille' => $this->editNomJeuneFille !== '' ? $this->editNomJeuneFille : null,
            'nationalite' => $this->editNationalite !== '' ? $this->editNationalite : null,
            'adresse_par_etablissement' => $this->editAdresseParEtablissement !== '' ? $this->editAdresseParEtablissement : null,
            'adresse_par_nom' => $this->editAdresseParNom !== '' ? $this->editAdresseParNom : null,
            'adresse_par_prenom' => $this->editAdresseParPrenom !== '' ? $this->editAdresseParPrenom : null,
            'adresse_par_telephone' => $this->editAdresseParTelephone !== '' ? $this->editAdresseParTelephone : null,
            'adresse_par_email' => $this->editAdresseParEmail !== '' ? $this->editAdresseParEmail : null,
            'adresse_par_adresse' => $this->editAdresseParAdresse !== '' ? $this->editAdresseParAdresse : null,
            'adresse_par_code_postal' => $this->editAdresseParCodePostal !== '' ? $this->editAdresseParCodePostal : null,
            'adresse_par_ville' => $this->editAdresseParVille !== '' ? $this->editAdresseParVille : null,
        ]);

        // Update medical data if user has permission
        if (Auth::user()?->peut_voir_donnees_sensibles) {
            ParticipantDonneesMedicales::updateOrCreate(
                ['participant_id' => $participant->id],
                [
                    'date_naissance' => $this->editDateNaissance !== '' ? $this->editDateNaissance : null,
                    'sexe' => $this->editSexe !== '' ? $this->editSexe : null,
                    'taille' => $this->editTaille !== '' ? $this->editTaille : null,
                    'poids' => $this->editPoids !== '' ? $this->editPoids : null,
                    'medecin_nom' => $this->editMedecinNom !== '' ? $this->editMedecinNom : null,
                    'medecin_prenom' => $this->editMedecinPrenom !== '' ? $this->editMedecinPrenom : null,
                    'medecin_telephone' => $this->editMedecinTelephone !== '' ? $this->editMedecinTelephone : null,
                    'medecin_email' => $this->editMedecinEmail !== '' ? $this->editMedecinEmail : null,
                    'medecin_adresse' => $this->editMedecinAdresse !== '' ? $this->editMedecinAdresse : null,
                    'medecin_code_postal' => $this->editMedecinCodePostal !== '' ? $this->editMedecinCodePostal : null,
                    'medecin_ville' => $this->editMedecinVille !== '' ? $this->editMedecinVille : null,
                    'therapeute_nom' => $this->editTherapeuteNom !== '' ? $this->editTherapeuteNom : null,
                    'therapeute_prenom' => $this->editTherapeutePrenom !== '' ? $this->editTherapeutePrenom : null,
                    'therapeute_telephone' => $this->editTherapeuteTelephone !== '' ? $this->editTherapeuteTelephone : null,
                    'therapeute_email' => $this->editTherapeuteEmail !== '' ? $this->editTherapeuteEmail : null,
                    'therapeute_adresse' => $this->editTherapeuteAdresse !== '' ? $this->editTherapeuteAdresse : null,
                    'therapeute_code_postal' => $this->editTherapeuteCodePostal !== '' ? $this->editTherapeuteCodePostal : null,
                    'therapeute_ville' => $this->editTherapeuteVille !== '' ? $this->editTherapeuteVille : null,
                    'notes' => $this->medNotes !== '' ? $this->medNotes : null,
                ]
            );
        }

        $this->successMessage = 'Modifications enregistrées.';
    }

    public function render(): View
    {
        $typeOp = $this->operation->typeOperation;
        $canSeeSensible = Auth::user()?->peut_voir_donnees_sensibles ?? false;

        return view('livewire.participant-show', [
            'typeOp' => $typeOp,
            'canSeeSensible' => $canSeeSensible,
            'hasParcours' => $typeOp?->formulaire_parcours_therapeutique && $canSeeSensible,
            'hasPrescripteur' => (bool) $typeOp?->formulaire_prescripteur,
            'hasEngagements' => $typeOp?->formulaire_parcours_therapeutique || $typeOp?->formulaire_droit_image,
            'hasDocuments' => $canSeeSensible && $typeOp?->formulaire_parcours_therapeutique,
        ]);
    }

    private function loadParticipantData(): void
    {
        $p = $this->participant;
        $tiers = $p->tiers;

        // Coordonnées
        $this->editNom = $tiers->nom ?? '';
        $this->editPrenom = $tiers->prenom ?? '';
        $this->editAdresse = $tiers->adresse_ligne1 ?? '';
        $this->editCodePostal = $tiers->code_postal ?? '';
        $this->editVille = $tiers->ville ?? '';
        $this->editTelephone = $tiers->telephone ?? '';
        $this->editEmail = $tiers->email ?? '';
        $this->editDateInscription = $p->date_inscription->format('Y-m-d');
        $this->editReferePar = $p->refere_par_id;
        $this->editTypeOperationTarifId = $p->type_operation_tarif_id;

        // Données personnelles
        $med = $p->donneesMedicales;
        $this->editDateNaissance = $med?->date_naissance ?? '';
        $this->editSexe = $med?->sexe ?? '';
        $this->editTaille = $med?->taille ?? '';
        $this->editPoids = $med?->poids ?? '';
        $this->editNomJeuneFille = $p->nom_jeune_fille ?? '';
        $this->editNationalite = $p->nationalite ?? '';

        // Contacts médicaux
        $this->editMedecinNom = $med?->medecin_nom ?? '';
        $this->editMedecinPrenom = $med?->medecin_prenom ?? '';
        $this->editMedecinTelephone = $med?->medecin_telephone ?? '';
        $this->editMedecinEmail = $med?->medecin_email ?? '';
        $this->editMedecinAdresse = $med?->medecin_adresse ?? '';
        $this->editMedecinCodePostal = $med?->medecin_code_postal ?? '';
        $this->editMedecinVille = $med?->medecin_ville ?? '';
        $this->editTherapeuteNom = $med?->therapeute_nom ?? '';
        $this->editTherapeutePrenom = $med?->therapeute_prenom ?? '';
        $this->editTherapeuteTelephone = $med?->therapeute_telephone ?? '';
        $this->editTherapeuteEmail = $med?->therapeute_email ?? '';
        $this->editTherapeuteAdresse = $med?->therapeute_adresse ?? '';
        $this->editTherapeuteCodePostal = $med?->therapeute_code_postal ?? '';
        $this->editTherapeuteVille = $med?->therapeute_ville ?? '';

        // Adressé par
        $this->editAdresseParEtablissement = $p->adresse_par_etablissement ?? '';
        $this->editAdresseParNom = $p->adresse_par_nom ?? '';
        $this->editAdresseParPrenom = $p->adresse_par_prenom ?? '';
        $this->editAdresseParTelephone = $p->adresse_par_telephone ?? '';
        $this->editAdresseParEmail = $p->adresse_par_email ?? '';
        $this->editAdresseParAdresse = $p->adresse_par_adresse ?? '';
        $this->editAdresseParCodePostal = $p->adresse_par_code_postal ?? '';
        $this->editAdresseParVille = $p->adresse_par_ville ?? '';

        // Notes
        $this->medNotes = $med?->notes ?? '';

        // Engagements (lecture seule)
        $this->editDroitImageLabel = $p->droit_image?->label();
        $this->editModePaiement = $p->mode_paiement_choisi;
        $this->editMoyenPaiement = $p->moyen_paiement_choisi;
        $this->editAutorisationContactMedecin = $p->autorisation_contact_medecin;
        $this->editRgpdAccepteAt = $p->rgpd_accepte_at?->format('d/m/Y à H:i');
        $this->editFormulaireRempliAt = $p->formulaireToken?->rempli_at?->format('d/m/Y à H:i');

        // Documents
        $this->editDocuments = Auth::user()?->peut_voir_donnees_sensibles
            ? $this->getParticipantDocuments($p->id)
            : [];
    }

    /** @return array<int, array{name: string, size: int, url: string}> */
    private function getParticipantDocuments(int $participantId): array
    {
        $dir = "participants/{$participantId}";
        if (! Storage::disk('local')->exists($dir)) {
            return [];
        }

        return collect(Storage::disk('local')->files($dir))
            ->map(fn (string $path) => [
                'name' => basename($path),
                'size' => Storage::disk('local')->size($path),
                'url' => route('gestion.participants.documents.download', [
                    'participant' => $participantId,
                    'filename' => basename($path),
                ]),
            ])
            ->toArray();
    }
}
```

- [ ] **Step 4 : Créer la vue Blade (structure avec onglets)**

Créer `resources/views/livewire/participant-show.blade.php` — reprendre le contenu des onglets existants du panneau d'édition dans `participant-table.blade.php` (lignes 439-951), adapté en page imbriquée au lieu de position-fixed. Inclure :
- Lien "← Retour à la liste des participants" qui dispatch un événement vers le parent
- Onglets Alpine.js identiques au panneau actuel (coordonnées, parcours, contacts_medicaux, prescripteur, notes, engagements, documents) + nouvel onglet **historique**
- Bouton Enregistrer en bas
- Message succès après sauvegarde
- Protection `beforeunload` JS pour modifications non sauvegardées

Structure clé de la vue :

```blade
<div x-data="{
    tab: 'coordonnees',
    isDirty: false,
    confirmLeave(callback) {
        if (this.isDirty && !confirm('Vous avez des modifications non sauvegardées. Quitter quand même ?')) return;
        callback();
    }
}"
x-on:input="isDirty = true"
x-init="window.addEventListener('beforeunload', (e) => { if (isDirty) { e.preventDefault(); e.returnValue = ''; } })">

    {{-- Header avec retour --}}
    <div class="d-flex justify-content-between align-items-center mb-3">
        <a href="#" @click.prevent="confirmLeave(() => $wire.dispatch('close-participant'))" class="text-decoration-none">
            <i class="bi bi-arrow-left me-1"></i> Retour à la liste des participants
        </a>
        <div class="d-flex gap-2">
            <a href="{{ route('gestion.operations.participants.fiche-pdf', [$operation, $participant]) }}" target="_blank" class="btn btn-sm btn-outline-info">
                <i class="bi bi-file-person"></i> Fiche PDF
            </a>
            @if($typeOp?->formulaire_droit_image && $participant->droit_image)
            <a href="{{ route('gestion.operations.participants.droit-image-pdf', [$operation, $participant]) }}" target="_blank" class="btn btn-sm btn-outline-info">
                <i class="bi bi-camera"></i> Autorisation photo
            </a>
            @endif
        </div>
    </div>

    <h5 class="fw-bold mb-3">{{ $participant->tiers->prenom }} {{ $participant->tiers->nom }}</h5>

    {{-- Onglets --}}
    <ul class="nav nav-tabs mb-3">
        ...mêmes onglets que le panneau actuel + onglet Historique...
    </ul>

    {{-- Contenu des onglets --}}
    <div style="max-width:800px;">
        ...copie exacte du contenu des onglets du panneau actuel...

        {{-- Nouvel onglet Historique --}}
        <div x-show="tab === 'historique'" x-cloak>
            ...voir Task 5...
        </div>
    </div>

    {{-- Bouton Enregistrer --}}
    <div class="mt-4 d-flex justify-content-between align-items-center">
        @if($successMessage)
            <span class="text-success small"><i class="bi bi-check-circle me-1"></i>{{ $successMessage }}</span>
        @else
            <span></span>
        @endif
        <button type="button" class="btn btn-primary" wire:click="save" x-on:click="isDirty = false">
            <i class="bi bi-check-lg me-1"></i> Enregistrer
        </button>
    </div>
</div>
```

La vue complète reprend trait pour trait le HTML des onglets dans `participant-table.blade.php` lignes 498-926 (coordonnées, parcours, contacts médicaux, prescripteur, notes, engagements, documents), en remplaçant les `wire:click` du panneau par ceux du nouveau composant. Ajouter le tab `historique` (contenu dans Task 5).

- [ ] **Step 5 : Exécuter les tests**

Run: `./vendor/bin/sail test tests/Feature/Livewire/ParticipantShowTest.php`
Expected: Tous les tests passent.

- [ ] **Step 6 : Commit**

```bash
git add app/Livewire/ParticipantShow.php resources/views/livewire/participant-show.blade.php tests/Feature/Livewire/ParticipantShowTest.php
git commit -m "feat: add ParticipantShow Livewire component with edit tabs"
```

---

## Task 4 : Tiers mapping dans ParticipantShow

**Files:**
- Modifier : `app/Livewire/ParticipantShow.php`

Les méthodes de mapping Tiers (mapAdresseParTiers, createAdresseParTiers, mapMedecinTiers, createMedecinTiers, mapTherapeuteTiers, createTherapeuteTiers, unlinkAdresseParTiers, unlinkMedecinTiers, unlinkTherapeuteTiers) doivent être portées depuis `ParticipantTable.php` (lignes 666-780).

- [ ] **Step 1 : Porter les méthodes de mapping**

Porter les 9 méthodes de mapping depuis `ParticipantTable.php` (lignes 666-785) vers `ParticipantShow.php`. Transformation requise :
- Remplacer `$this->editingParticipant()` par `$this->participant` (accès direct)
- Remplacer `$this->openEditModal($this->editParticipantId)` par `$this->participant->refresh(); $this->loadParticipantData();`
- Supprimer la méthode `editingParticipant()` (inutile ici)

Ajouter `use App\Models\Tiers;` en import.

Exemple pour `mapAdresseParTiers` (même pattern pour les 8 autres) :

```php
public function mapAdresseParTiers(): void
{
    if ($this->mapAdresseParTiersId === null) {
        return;
    }
    $this->participant->update(['refere_par_id' => $this->mapAdresseParTiersId]);
    $this->dispatch('notify', message: 'Tiers associé au prescripteur.');
    $this->participant->refresh();
    $this->loadParticipantData();
}

public function createAdresseParTiers(): void
{
    $tiers = Tiers::create([
        'nom' => $this->participant->adresse_par_nom,
        'prenom' => $this->participant->adresse_par_prenom,
        'entreprise' => $this->participant->adresse_par_etablissement,
        'telephone' => $this->participant->adresse_par_telephone,
        'email' => $this->participant->adresse_par_email,
        'adresse_ligne1' => $this->participant->adresse_par_adresse,
        'code_postal' => $this->participant->adresse_par_code_postal,
        'ville' => $this->participant->adresse_par_ville,
        'type' => 'particulier',
    ]);
    $this->participant->update(['refere_par_id' => $tiers->id]);
    $this->dispatch('notify', message: 'Tiers créé et associé.');
    $this->participant->refresh();
    $this->loadParticipantData();
}

public function unlinkAdresseParTiers(): void
{
    $this->participant->update(['refere_par_id' => null]);
    $this->dispatch('notify', message: 'Association supprimée.');
    $this->participant->refresh();
    $this->loadParticipantData();
}
```

Appliquer le même pattern pour `mapMedecinTiers`, `createMedecinTiers`, `unlinkMedecinTiers`, `mapTherapeuteTiers`, `createTherapeuteTiers`, `unlinkTherapeuteTiers` — en remplaçant `$this->editingParticipant()` par `$this->participant` et `$p->donneesMedicales` par `$this->participant->donneesMedicales`.

- [ ] **Step 2 : Exécuter les tests existants**

Run: `./vendor/bin/sail test tests/Feature/Livewire/ParticipantShowTest.php`
Expected: Toujours vert.

- [ ] **Step 3 : Commit**

```bash
git add app/Livewire/ParticipantShow.php
git commit -m "feat: port tiers mapping methods to ParticipantShow"
```

---

## Task 5 : Onglet Historique dans ParticipantShow

**Files:**
- Modifier : `app/Livewire/ParticipantShow.php`
- Modifier : `resources/views/livewire/participant-show.blade.php`
- Modifier : `tests/Feature/Livewire/ParticipantShowTest.php`

- [ ] **Step 1 : Écrire le test**

Ajouter dans `tests/Feature/Livewire/ParticipantShowTest.php` :

```php
use App\Models\EmailLog;
use App\Models\FormulaireToken;

it('shows historique tab with email logs', function () {
    EmailLog::create([
        'participant_id' => $this->participant->id,
        'tiers_id' => $this->tiers->id,
        'operation_id' => $this->operation->id,
        'categorie' => 'formulaire',
        'destinataire_email' => 'marie@test.com',
        'destinataire_nom' => 'Dupont Marie',
        'objet' => 'Votre formulaire',
        'statut' => 'envoye',
    ]);

    Livewire::test(ParticipantShow::class, [
        'operation' => $this->operation,
        'participant' => $this->participant,
    ])
        ->set('activeTab', 'historique')
        ->assertSee('Votre formulaire')
        ->assertSee('marie@test.com');
});

it('shows formulaire rempli in historique', function () {
    FormulaireToken::create([
        'participant_id' => $this->participant->id,
        'token' => 'ABCD-EFGH',
        'expire_at' => '2026-12-31',
        'rempli_at' => '2026-03-15 14:30:00',
        'rempli_ip' => '82.123.45.67',
    ]);

    Livewire::test(ParticipantShow::class, [
        'operation' => $this->operation,
        'participant' => $this->participant,
    ])
        ->set('activeTab', 'historique')
        ->assertSee('Formulaire rempli')
        ->assertSee('15/03/2026');
});
```

- [ ] **Step 2 : Vérifier que les tests échouent**

Run: `./vendor/bin/sail test tests/Feature/Livewire/ParticipantShowTest.php --filter="historique"`
Expected: FAIL.

- [ ] **Step 3 : Ajouter la logique historique dans le composant**

Dans `ParticipantShow.php`, ajouter dans `render()` les données pour l'historique :

```php
// Dans render(), avant le return :
$emailLogs = EmailLog::where('participant_id', $this->participant->id)
    ->orderByDesc('created_at')
    ->get();

$formulaireToken = $this->participant->formulaireToken;

// Construire la timeline combinée
$timeline = collect();

foreach ($emailLogs as $log) {
    $timeline->push([
        'date' => $log->created_at,
        'type' => 'email',
        'categorie' => $log->categorie,
        'icon' => 'bi-envelope',
        'color' => $log->statut === 'envoye' ? 'success' : 'danger',
        'description' => $log->statut === 'envoye'
            ? "Email {$log->categorie} envoyé à {$log->destinataire_email}"
            : "Erreur envoi {$log->categorie} à {$log->destinataire_email}",
        'detail' => $log->objet,
    ]);
}

if ($formulaireToken?->rempli_at) {
    $timeline->push([
        'date' => $formulaireToken->rempli_at,
        'type' => 'formulaire_rempli',
        'categorie' => 'formulaire',
        'icon' => 'bi-check-circle-fill',
        'color' => 'primary',
        'description' => 'Formulaire rempli depuis ' . $formulaireToken->rempli_ip,
        'detail' => null,
    ]);
}

$timeline = $timeline->sortByDesc('date')->values();

// Ajouter au return view :
'timeline' => $timeline,
```

Ne pas oublier le `use App\Models\EmailLog;` en haut du fichier.

- [ ] **Step 4 : Ajouter le contenu de l'onglet Historique dans la vue**

Dans `participant-show.blade.php`, onglet historique :

```blade
<div x-show="tab === 'historique'" x-cloak>
    @if($timeline->isEmpty())
        <p class="text-muted text-center py-4">Aucun événement enregistré.</p>
    @else
        <div class="list-group list-group-flush">
            @foreach($timeline as $event)
                <div class="list-group-item px-0">
                    <div class="d-flex align-items-start gap-3">
                        <div class="text-{{ $event['color'] }}" style="font-size:1.2rem;">
                            <i class="bi {{ $event['icon'] }}"></i>
                        </div>
                        <div class="flex-grow-1">
                            <div class="fw-semibold small">{{ $event['description'] }}</div>
                            @if($event['detail'])
                                <div class="text-muted small">{{ $event['detail'] }}</div>
                            @endif
                        </div>
                        <div class="text-muted small text-nowrap">
                            {{ $event['date']->format('d/m/Y à H:i') }}
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
```

- [ ] **Step 5 : Exécuter les tests**

Run: `./vendor/bin/sail test tests/Feature/Livewire/ParticipantShowTest.php`
Expected: Tous les tests passent.

- [ ] **Step 6 : Commit**

```bash
git add app/Livewire/ParticipantShow.php resources/views/livewire/participant-show.blade.php tests/Feature/Livewire/ParticipantShowTest.php
git commit -m "feat: add Historique tab with email logs and formulaire timeline"
```

---

## Task 6 : Intégration dans GestionOperations + navigation

**Files:**
- Modifier : `app/Livewire/GestionOperations.php`
- Modifier : `resources/views/livewire/gestion-operations.blade.php`

- [ ] **Step 1 : Modifier GestionOperations.php**

Ajouter la propriété URL pour le participant sélectionné :

```php
#[Url(as: 'participant')]
public ?int $selectedParticipantId = null;
```

Ajouter les méthodes d'événement pour naviguer entre liste et fiche :

```php
#[On('open-participant')]
public function openParticipant(int $id): void
{
    $this->selectedParticipantId = $id;
    $this->activeTab = 'participants';
}

#[On('close-participant')]
public function closeParticipant(): void
{
    $this->selectedParticipantId = null;
}
```

Ajouter les imports : `use Livewire\Attributes\On;` et `use App\Models\Participant;`.

Dans `render()`, charger le participant sélectionné si besoin. Ajouter après la ligne `$selectedOperation = ...` :

```php
$selectedParticipant = null;
if ($this->selectedParticipantId && $selectedOperation) {
    $selectedParticipant = Participant::where('operation_id', $selectedOperation->id)
        ->find($this->selectedParticipantId);
    if ($selectedParticipant) {
        $this->activeTab = 'participants';
    } else {
        $this->selectedParticipantId = null;
    }
}
```

Ajouter `'selectedParticipant' => $selectedParticipant,` au tableau passé à la vue.

- [ ] **Step 2 : Modifier la vue gestion-operations.blade.php**

Remplacer le bloc participants (ligne 140-142) :

```blade
@if($activeTab === 'participants')
    @if($selectedParticipant)
        <livewire:participant-show
            :operation="$selectedOperation"
            :participant="$selectedParticipant"
            :key="'ps-'.$selectedParticipant->id"
        />
    @else
        <livewire:participant-table :operation="$selectedOperation" :key="'pt-'.$selectedOperation->id" />
    @endif
@endif
```

- [ ] **Step 3 : Exécuter tous les tests**

Run: `./vendor/bin/sail test`
Expected: Tous les tests passent.

- [ ] **Step 4 : Commit**

```bash
git add app/Livewire/GestionOperations.php resources/views/livewire/gestion-operations.blade.php
git commit -m "feat: integrate ParticipantShow into GestionOperations via URL parameter"
```

---

## Task 7 : Simplifier ParticipantTable — supprimer le panneau d'édition

**Files:**
- Modifier : `app/Livewire/ParticipantTable.php`
- Modifier : `resources/views/livewire/participant-table.blade.php`

- [ ] **Step 1 : Remplacer le clic nom par une navigation via dispatch**

Dans `participant-table.blade.php`, le nom du participant est affiché dans un `<td>` avec inline editing. Ajouter un lien cliquable qui dispatch un événement vers `GestionOperations` (le composant parent). Note : `$parent` n'est pas disponible en Livewire 4 — on utilise `$dispatch` à la place.

```blade
<a href="#" wire:click.prevent="$dispatch('open-participant', { id: {{ $participant->id }} })" class="text-decoration-none fw-semibold">
    {{ $participant->tiers->nom }} {{ $participant->tiers->prenom }}
</a>
```

Ajouter le listener correspondant dans `GestionOperations.php` :

```php
#[On('open-participant')]
public function openParticipant(int $id): void
{
    $this->selectedParticipantId = $id;
    $this->activeTab = 'participants';
}
```

Ajouter `use Livewire\Attributes\On;` en import si pas déjà fait (normalement ajouté en Task 6 pour `close-participant`).

- [ ] **Step 2 : Supprimer les propriétés d'édition de ParticipantTable.php**

Supprimer les propriétés suivantes (lignes 49-179) de `ParticipantTable.php` :
- Tout le bloc "Edit modal" : `showEditModal`, `editParticipantId`, `editParticipant`, `mapAdresseParTiersId`, `mapMedecinTiersId`, `mapTherapeuteTiersId`, `editNom` → `editAdresseParVille`, `editDroitImageLabel` → `editFormulaireRempliAt`, `editDocuments`
- Conserver les propriétés "Add modal" (lignes 28-47), "Notes modal" (155-160), "Token modal" (162-175)

Supprimer les méthodes suivantes :
- `openEditModal()` (lignes 261-339)
- `saveEdit()` (lignes 341-405)
- `getParticipantDocuments()` (lignes 621-643)
- Toutes les méthodes de mapping Tiers (lignes 666-780) — elles sont maintenant dans `ParticipantShow`

Conserver :
- `mount()`, `render()`
- `openAddModal()`, `onTiersSelected()`, `addParticipant()`, `quickAddParticipant()`
- `updateTiersField()`, `updateParticipantField()`, `updateMedicalField()`
- `removeParticipant()`
- `openNotesModal()`, `saveNotes()` (la modale notes reste accessible depuis la liste)
- `genererToken()`, `genererTokenAvecDate()`, `ouvrirToken()`, `envoyerTokenParEmail()`
- `isAdherent()`

- [ ] **Step 3 : Supprimer le panneau d'édition de la vue Blade**

Dans `participant-table.blade.php`, supprimer tout le bloc `@if($showEditModal)` (lignes 439-951).

- [ ] **Step 4 : Exécuter tous les tests**

Run: `./vendor/bin/sail test`
Expected: Tous les tests passent (les tests de ParticipantTable existants ne testent pas le panneau d'édition en détail).

- [ ] **Step 5 : Lancer Pint**

Run: `./vendor/bin/sail exec laravel.test ./vendor/bin/pint`

- [ ] **Step 6 : Commit**

```bash
git add app/Livewire/ParticipantTable.php resources/views/livewire/participant-table.blade.php
git commit -m "refactor: remove edit panel from ParticipantTable, replaced by ParticipantShow"
```

---

## Task 8 : Tests finaux et vérifications

- [ ] **Step 1 : Exécuter toute la suite de tests**

Run: `./vendor/bin/sail test`
Expected: Tous les tests passent.

- [ ] **Step 2 : Lancer Pint sur tout le projet**

Run: `./vendor/bin/sail exec laravel.test ./vendor/bin/pint`

- [ ] **Step 3 : Vérifier la migration fresh**

Run: `./vendor/bin/sail artisan migrate:fresh --seed`
Expected: Aucune erreur.

- [ ] **Step 4 : Commit final si corrections Pint**

```bash
git add -A && git commit -m "style: apply Pint formatting"
```
