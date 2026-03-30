# Attestations de présence — Plan d'implémentation

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Permettre de générer des PDF d'attestation de présence (par séance ou récapitulatif) et de les envoyer par email aux participants depuis l'écran Séances.

**Architecture:** Migration pour le cachet/signature sur Association + contrôleur PDF DomPDF + Mailable avec PJ + composant Livewire AttestationModal embarqué dans SeanceTable. Réutilise le pattern existant de SeancePdfController pour les logos et FormulaireInvitation pour l'email.

**Tech Stack:** Laravel 11, Livewire 4, DomPDF, Bootstrap 5, Pest PHP

**Spec:** `docs/superpowers/specs/2026-03-30-attestations-presence-design.md`

---

## Fichiers concernés

| Action | Fichier | Responsabilité |
|--------|---------|----------------|
| Créer | `database/migrations/2026_03_30_200002_add_cachet_signature_to_association.php` | Champ cachet_signature_path |
| Modifier | `app/Models/Association.php` | Ajouter cachet_signature_path au fillable/casts |
| Modifier | `app/Livewire/Parametres/AssociationForm.php` | Upload cachet/signature |
| Modifier | `resources/views/livewire/parametres/association-form.blade.php` | Champ upload UI |
| Créer | `app/Http/Controllers/AttestationPresencePdfController.php` | Génération PDF (séance + récap) |
| Créer | `resources/views/pdf/attestation-presence.blade.php` | Template PDF |
| Créer | `app/Mail/AttestationPresenceMail.php` | Mailable avec PJ PDF |
| Créer | `resources/views/emails/attestation-presence.blade.php` | Template email |
| Créer | `app/Livewire/AttestationModal.php` | Modales séance + récap |
| Créer | `resources/views/livewire/attestation-modal.blade.php` | Vue des modales |
| Modifier | `resources/views/livewire/seance-table.blade.php:218-231` | Boutons attestation dans le footer + ligne participant |
| Modifier | `routes/web.php` | Routes PDF attestation |
| Créer | `tests/Feature/AttestationPresenceTest.php` | Tests PDF + email + modal |

---

## Task 1 : Migration cachet/signature + upload dans Paramètres

**Files:**
- Créer : `database/migrations/2026_03_30_200002_add_cachet_signature_to_association.php`
- Modifier : `app/Models/Association.php`
- Modifier : `app/Livewire/Parametres/AssociationForm.php`
- Modifier : `resources/views/livewire/parametres/association-form.blade.php`
- Créer : `tests/Feature/AttestationPresenceTest.php`

- [ ] **Step 1 : Écrire le test**

```php
// tests/Feature/AttestationPresenceTest.php
<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\User;

it('can upload cachet signature in association settings', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $association = new Association;
    $association->id = 1;
    $association->nom = 'Test Asso';
    $association->save();

    expect($association->cachet_signature_path)->toBeNull();
});
```

- [ ] **Step 2 : Vérifier que le test échoue**

Run: `./vendor/bin/sail test tests/Feature/AttestationPresenceTest.php`
Expected: FAIL — colonne `cachet_signature_path` n'existe pas.

- [ ] **Step 3 : Créer la migration**

```php
// database/migrations/2026_03_30_200002_add_cachet_signature_to_association.php
Schema::table('association', function (Blueprint $table) {
    $table->string('cachet_signature_path')->nullable()->after('logo_path');
});
```

- [ ] **Step 4 : Modifier le modèle Association**

Dans `app/Models/Association.php`, ajouter `'cachet_signature_path'` au tableau `$fillable` et au tableau `casts()`.

- [ ] **Step 5 : Modifier AssociationForm.php**

Ajouter les propriétés :
```php
public $cachet = null;
public ?string $cachet_signature_path = null;
```

Dans `mount()`, charger `$this->cachet_signature_path = $association->cachet_signature_path;`

Dans `save()` :
- Ajouter validation `'cachet' => ['nullable', 'image', 'mimes:png,jpg,jpeg', 'max:2048']`
- Ajouter bloc upload identique au logo mais avec chemin `'association/cachet.{ext}'`
- Stocker dans `$data['cachet_signature_path']`

Dans `render()`, résoudre l'URL du cachet comme pour le logo et passer `$cachetUrl` à la vue.

- [ ] **Step 6 : Modifier la vue association-form.blade.php**

Après le bloc logo existant, ajouter un bloc identique pour le cachet/signature :
- Prévisualisation de l'image existante
- Champ file upload `wire:model="cachet"`
- Label : "Cachet et signature du président"

- [ ] **Step 7 : Exécuter migration et tests**

Run: `./vendor/bin/sail artisan migrate && ./vendor/bin/sail test tests/Feature/AttestationPresenceTest.php`

- [ ] **Step 8 : Commit**

```bash
git commit -m "feat: add cachet/signature upload to association settings"
```

---

## Task 2 : PDF Attestation de présence

**Files:**
- Créer : `app/Http/Controllers/AttestationPresencePdfController.php`
- Créer : `resources/views/pdf/attestation-presence.blade.php`
- Modifier : `routes/web.php`
- Modifier : `tests/Feature/AttestationPresenceTest.php`

- [ ] **Step 1 : Écrire les tests**

```php
use App\Models\Operation;
use App\Models\Participant;
use App\Models\Seance;
use App\Models\Presence;
use App\Models\Tiers;
use App\Models\TypeOperation;
use App\Enums\StatutPresence;

it('generates attestation PDF for a seance', function () {
    $user = User::factory()->create(['peut_voir_donnees_sensibles' => true]);
    $this->actingAs($user);

    $typeOp = TypeOperation::factory()->create();
    $operation = Operation::factory()->create(['type_operation_id' => $typeOp->id]);
    $seance = Seance::create(['operation_id' => $operation->id, 'numero' => 1, 'date' => '2026-03-15']);
    $tiers = Tiers::factory()->create(['nom' => 'Dupont', 'prenom' => 'Marie']);
    $participant = Participant::create([
        'tiers_id' => $tiers->id,
        'operation_id' => $operation->id,
        'date_inscription' => '2026-01-15',
    ]);
    Presence::create([
        'seance_id' => $seance->id,
        'participant_id' => $participant->id,
        'statut' => StatutPresence::Present->value,
    ]);

    (new Association)->forceFill(['id' => 1, 'nom' => 'Test Asso', 'ville' => 'Paris'])->save();

    $response = $this->get(route('gestion.operations.seances.attestation-pdf', [
        $operation, $seance, 'participants' => $participant->id,
    ]));

    $response->assertOk();
    $response->assertHeader('content-type', 'application/pdf');
});

it('generates recap attestation PDF for a participant', function () {
    $user = User::factory()->create(['peut_voir_donnees_sensibles' => true]);
    $this->actingAs($user);

    $typeOp = TypeOperation::factory()->create();
    $operation = Operation::factory()->create(['type_operation_id' => $typeOp->id]);
    $seance = Seance::create(['operation_id' => $operation->id, 'numero' => 1, 'date' => '2026-03-15']);
    $tiers = Tiers::factory()->create(['nom' => 'Dupont', 'prenom' => 'Marie']);
    $participant = Participant::create([
        'tiers_id' => $tiers->id,
        'operation_id' => $operation->id,
        'date_inscription' => '2026-01-15',
    ]);
    Presence::create([
        'seance_id' => $seance->id,
        'participant_id' => $participant->id,
        'statut' => StatutPresence::Present->value,
    ]);

    (new Association)->forceFill(['id' => 1, 'nom' => 'Test Asso', 'ville' => 'Paris'])->save();

    $response = $this->get(route('gestion.operations.participants.attestation-recap-pdf', [
        $operation, $participant,
    ]));

    $response->assertOk();
    $response->assertHeader('content-type', 'application/pdf');
});
```

- [ ] **Step 2 : Vérifier que les tests échouent**

- [ ] **Step 3 : Ajouter les routes**

Dans `routes/web.php`, dans le groupe gestion, ajouter :

```php
Route::get('/operations/{operation}/seances/{seance}/attestation-pdf', [AttestationPresencePdfController::class, 'seance'])
    ->name('gestion.operations.seances.attestation-pdf');
Route::get('/operations/{operation}/participants/{participant}/attestation-recap-pdf', [AttestationPresencePdfController::class, 'recap'])
    ->name('gestion.operations.participants.attestation-recap-pdf');
```

- [ ] **Step 4 : Créer le contrôleur AttestationPresencePdfController**

Suivre le pattern exact de `SeancePdfController` :
- Méthode `seance(Operation $operation, Seance $seance)` :
  - Valider query param `participants` (IDs entiers, appartiennent à l'opération, présents à la séance)
  - Charger participants avec `tiers`, `donneesMedicales`
  - Résoudre logos + cachet via méthode privée (copier `getAssociationData` de SeancePdfController + ajouter résolution cachet)
  - `Pdf::loadView('pdf.attestation-presence', [...])` avec `$mode = 'seance'`
  - Stream avec filename `Attestation présence - {opération} - S{numéro}.pdf`

- Méthode `recap(Operation $operation, Participant $participant)` :
  - Vérifier que le participant appartient à l'opération
  - Charger les séances où le participant est présent (join presences, statut = 'present')
  - Même résolution logos + cachet
  - `$mode = 'recap'`
  - Filename `Attestation présence - {opération} - {prénom} {nom}.pdf`

- [ ] **Step 5 : Créer la vue PDF attestation-presence.blade.php**

Reprendre le layout de `seance-emargement.blade.php` comme base :
- Même header avec logos (headerLogoBase64/footerLogoBase64)
- Même styles CSS inline pour DomPDF
- Titre "Attestation de présence"
- **Mode séance :** boucle `@foreach($participants as $p)` avec page-break, texte attestation avec nom/prénom/date naissance (si disponible)/séance
- **Mode récap :** texte attestation + tableau des séances (n°, date, titre) + total
- Pied : "Fait à {ville}, le {date}" + image cachet si `$cachetBase64` non null
- Footer : "Généré le {date heure}"

- [ ] **Step 6 : Exécuter les tests**

Run: `./vendor/bin/sail test tests/Feature/AttestationPresenceTest.php`

- [ ] **Step 7 : Commit**

```bash
git commit -m "feat: add AttestationPresencePdfController with seance and recap PDF generation"
```

---

## Task 3 : Mailable AttestationPresenceMail

**Files:**
- Créer : `app/Mail/AttestationPresenceMail.php`
- Créer : `resources/views/emails/attestation-presence.blade.php`
- Modifier : `tests/Feature/AttestationPresenceTest.php`

- [ ] **Step 1 : Écrire le test**

```php
use App\Mail\AttestationPresenceMail;
use Illuminate\Support\Facades\Mail;

it('sends attestation email with PDF attachment', function () {
    Mail::fake();
    $user = User::factory()->create(['peut_voir_donnees_sensibles' => true]);
    $this->actingAs($user);

    $typeOp = TypeOperation::factory()->create(['email_from' => 'asso@test.com']);
    $operation = Operation::factory()->create(['type_operation_id' => $typeOp->id]);

    $mail = new AttestationPresenceMail(
        prenomParticipant: 'Marie',
        nomParticipant: 'Dupont',
        nomOperation: $operation->nom,
        nomTypeOperation: $typeOp->nom,
        dateDebut: '15/09/2025',
        dateFin: '30/06/2026',
        nombreSeances: '30',
        numeroSeance: '5',
        dateSeance: '15/03/2026',
        customObjet: 'Attestation — {operation}',
        customCorps: '<p>Bonjour {prenom}, voici votre attestation.</p>',
        pdfContent: '%PDF-fake-content',
        pdfFilename: 'attestation.pdf',
    );

    expect($mail->subject)->not->toBeEmpty();
    expect($mail->attachments())->toHaveCount(1);
});
```

- [ ] **Step 2 : Créer le Mailable**

`app/Mail/AttestationPresenceMail.php` — même pattern que `FormulaireInvitation` :
- Readonly constructor properties
- `envelope()` : subject from `customObjet` avec substitution variables
- `content()` : vue `emails.attestation-presence` avec `corpsHtml` (substitution variables dans `customCorps`)
- `attachments()` : `Attachment::fromData(fn () => $this->pdfContent, $this->pdfFilename)->withMime('application/pdf')`
- Méthode privée `variables()` qui retourne le tableau de substitution (mêmes clés que `CategorieEmail::Attestation`)

- [ ] **Step 3 : Créer la vue email**

`resources/views/emails/attestation-presence.blade.php` — même structure que `formulaire-invitation.blade.php` mais sans le bloc auto (URL/bouton). Juste le corps HTML personnalisé `{!! $corpsHtml !!}`.

- [ ] **Step 4 : Tests + Pint + Commit**

Run: `./vendor/bin/sail test tests/Feature/AttestationPresenceTest.php`

```bash
git commit -m "feat: add AttestationPresenceMail mailable with PDF attachment"
```

---

## Task 4 : Composant Livewire AttestationModal

**Files:**
- Créer : `app/Livewire/AttestationModal.php`
- Créer : `resources/views/livewire/attestation-modal.blade.php`
- Modifier : `tests/Feature/AttestationPresenceTest.php`

- [ ] **Step 1 : Écrire les tests**

```php
use App\Livewire\AttestationModal;
use App\Models\EmailLog;
use App\Models\EmailTemplate;
use Livewire\Livewire;

it('opens seance attestation modal with present participants', function () {
    $user = User::factory()->create(['peut_voir_donnees_sensibles' => true]);
    $this->actingAs($user);

    $typeOp = TypeOperation::factory()->create(['email_from' => 'asso@test.com']);
    $operation = Operation::factory()->create(['type_operation_id' => $typeOp->id]);
    $seance = Seance::create(['operation_id' => $operation->id, 'numero' => 1, 'date' => '2026-03-15']);
    $tiers = Tiers::factory()->create(['nom' => 'Dupont', 'prenom' => 'Marie', 'email' => 'marie@test.com']);
    $participant = Participant::create(['tiers_id' => $tiers->id, 'operation_id' => $operation->id, 'date_inscription' => now()]);
    Presence::create(['seance_id' => $seance->id, 'participant_id' => $participant->id, 'statut' => StatutPresence::Present->value]);

    (new Association)->forceFill(['id' => 1, 'nom' => 'Test Asso', 'ville' => 'Paris'])->save();

    Livewire::test(AttestationModal::class, ['operation' => $operation])
        ->call('openSeanceModal', $seance->id)
        ->assertSee('Dupont')
        ->assertSee('Marie');
});

it('sends attestation emails and logs them', function () {
    Mail::fake();
    $user = User::factory()->create(['peut_voir_donnees_sensibles' => true]);
    $this->actingAs($user);

    $typeOp = TypeOperation::factory()->create(['email_from' => 'asso@test.com']);
    $operation = Operation::factory()->create(['type_operation_id' => $typeOp->id]);
    $seance = Seance::create(['operation_id' => $operation->id, 'numero' => 1, 'date' => '2026-03-15']);
    $tiers = Tiers::factory()->create(['nom' => 'Dupont', 'prenom' => 'Marie', 'email' => 'marie@test.com']);
    $participant = Participant::create(['tiers_id' => $tiers->id, 'operation_id' => $operation->id, 'date_inscription' => now()]);
    Presence::create(['seance_id' => $seance->id, 'participant_id' => $participant->id, 'statut' => StatutPresence::Present->value]);

    (new Association)->forceFill(['id' => 1, 'nom' => 'Test Asso', 'ville' => 'Paris'])->save();
    EmailTemplate::create(['categorie' => 'attestation', 'objet' => 'Attestation — {operation}', 'corps' => '<p>Bonjour {prenom}</p>']);

    Livewire::test(AttestationModal::class, ['operation' => $operation])
        ->call('openSeanceModal', $seance->id)
        ->call('envoyerParEmail');

    $log = EmailLog::where('participant_id', $participant->id)->where('categorie', 'attestation')->first();
    expect($log)->not->toBeNull()
        ->and($log->statut)->toBe('envoye');
});
```

- [ ] **Step 2 : Créer le composant AttestationModal.php**

Propriétés :
```php
public Operation $operation;
public string $mode = ''; // 'seance' ou 'recap'
public bool $showModal = false;
public ?int $seanceId = null;
public ?int $participantId = null;
public string $modalTitle = '';
public array $presentParticipants = []; // [{id, nom, prenom, email, checked}]
public string $resultMessage = '';
public string $resultType = '';
public bool $hasCachet = false;
public bool $hasEmailFrom = false;
```

Méthodes :
- `mount(Operation $operation)` : stocker opération, vérifier `hasEmailFrom`
- `openSeanceModal(int $seanceId)` : charger les participants présents à cette séance, construire `$presentParticipants` avec checkbox par défaut true (false si pas d'email), set `$mode = 'seance'`
- `openRecapModal(int $participantId)` : charger les séances où le participant est présent, set `$mode = 'recap'`
- `toggleParticipant(int $id)` : toggle checked dans `$presentParticipants`
- `envoyerParEmail()` : pour chaque participant coché avec email, générer le PDF (via le contrôleur ou inline avec Pdf::loadView), créer AttestationPresenceMail avec le PDF en PJ, envoyer, créer EmailLog. Afficher résumé.
- `telechargerPdf()` : rediriger vers la route PDF avec les participants cochés
- `render()` : passer `$hasCachet`, `$hasEmailFrom` à la vue

**Important :** La génération du PDF pour l'email doit être faite en mémoire (pas de fichier temp). Utiliser `Pdf::loadView(...)->output()` pour obtenir le contenu binaire, puis le passer au Mailable.

- [ ] **Step 3 : Créer la vue attestation-modal.blade.php**

Deux modales (même pattern que les modales existantes dans participant-table) :

**Modale séance :**
```blade
@if($showModal && $mode === 'seance')
<div class="position-fixed top-0 start-0 w-100 h-100 d-flex align-items-center justify-content-center"
     style="background:rgba(0,0,0,.4);z-index:2000" wire:click.self="$set('showModal', false)">
    <div class="bg-white rounded p-4 shadow" style="width:550px;max-width:95vw;max-height:90vh;overflow-y:auto">
        <h6 class="fw-bold mb-3">{{ $modalTitle }}</h6>

        @if(!$hasCachet)
            <div class="alert alert-warning py-2 small">
                <i class="bi bi-exclamation-triangle me-1"></i> Cachet et signature non configuré dans les paramètres.
            </div>
        @endif

        <div class="list-group list-group-flush mb-3">
            @foreach($presentParticipants as $index => $p)
                <label class="list-group-item d-flex align-items-center gap-2 {{ !$p['email'] ? 'text-muted' : '' }}">
                    <input type="checkbox" class="form-check-input"
                           wire:click="toggleParticipant({{ $p['id'] }})"
                           {{ $p['checked'] ? 'checked' : '' }}
                           {{ !$p['email'] ? 'disabled' : '' }}>
                    <span>{{ $p['nom'] }} {{ $p['prenom'] }}</span>
                    @if(!$p['email'])
                        <small class="text-muted ms-auto">pas d'email</small>
                    @else
                        <small class="text-muted ms-auto">{{ $p['email'] }}</small>
                    @endif
                </label>
            @endforeach
        </div>

        @if($resultMessage)
            <div class="alert alert-{{ $resultType }} py-2 small mb-3">{{ $resultMessage }}</div>
        @endif

        <div class="d-flex justify-content-end gap-2">
            <button class="btn btn-sm btn-outline-secondary" wire:click="$set('showModal', false)">Fermer</button>
            <a href="#" wire:click.prevent="telechargerPdf" class="btn btn-sm btn-outline-primary">
                <i class="bi bi-download me-1"></i> Télécharger PDF
            </a>
            <button class="btn btn-sm btn-primary" wire:click="envoyerParEmail" {{ !$hasEmailFrom ? 'disabled title=Email expéditeur non configuré' : '' }}>
                <i class="bi bi-envelope me-1"></i> Envoyer par email
                <span wire:loading wire:target="envoyerParEmail">...</span>
            </button>
        </div>
    </div>
</div>
@endif
```

**Modale récap :** même structure mais affiche la liste des séances au lieu des checkboxes participants, avec deux boutons (envoyer + télécharger).

- [ ] **Step 4 : Tests + Pint + Commit**

```bash
git commit -m "feat: add AttestationModal Livewire component with send and download"
```

---

## Task 5 : Intégration dans SeanceTable

**Files:**
- Modifier : `resources/views/livewire/seance-table.blade.php`

- [ ] **Step 1 : Ajouter le composant AttestationModal dans la vue**

Au début de `seance-table.blade.php` (après le `<div>` racine), insérer :
```blade
<livewire:attestation-modal :operation="$operation" :key="'am-'.$operation->id" />
```

- [ ] **Step 2 : Ajouter la ligne "Attestations" dans le footer**

Après la ligne "Feuilles de présence" (ligne 230), avant `</tfoot>`, ajouter une nouvelle `<tr>` :

```blade
<tr style="background:#f8f8f8;font-size:12px">
    <td style="position:sticky;left:0;z-index:1;background:#f8f8f8;color:#888">Attestations</td>
    @foreach($seances as $seance)
        <td style="text-align:center">
            <button type="button" class="btn btn-link btn-sm p-0"
                    style="color:#A9014F;text-decoration:none"
                    wire:click="$dispatchTo('attestation-modal', 'open-seance-modal', { seanceId: {{ $seance->id }} })"
                    title="Attestations séance S{{ $seance->numero }}">
                <i class="bi bi-envelope-paper"></i>
            </button>
        </td>
    @endforeach
</tr>
```

- [ ] **Step 3 : Ajouter le bouton récap sur chaque ligne participant**

Dans la cellule `<td rowspan="2">` du nom participant (ligne 124), ajouter un bouton après le nom :

```blade
<td rowspan="2" style="position:sticky;left:0;z-index:1;background:#fff;font-weight:500;white-space:nowrap;vertical-align:middle;font-size:11px">
    {{ $participant->tiers->nom }} {{ $participant->tiers->prenom }}
    <button type="button" class="btn btn-link btn-sm p-0 ms-1"
            style="color:#888;text-decoration:none"
            wire:click="$dispatchTo('attestation-modal', 'open-recap-modal', { participantId: {{ $participant->id }} })"
            title="Attestation récapitulative">
        <i class="bi bi-file-earmark-text" style="font-size:11px"></i>
    </button>
</td>
```

- [ ] **Step 4 : Ajouter les listeners dans AttestationModal.php**

Ajouter `#[On('open-seance-modal')]` sur `openSeanceModal()` et `#[On('open-recap-modal')]` sur `openRecapModal()`.

- [ ] **Step 5 : Tests manuels + Pint + Commit**

Run: `./vendor/bin/sail test`

```bash
git commit -m "feat: integrate attestation buttons into SeanceTable matrix"
```

---

## Task 6 : Tests finaux et vérifications

- [ ] **Step 1 : Exécuter toute la suite de tests**

Run: `./vendor/bin/sail test`

- [ ] **Step 2 : Lancer Pint**

Run: `./vendor/bin/sail exec laravel.test ./vendor/bin/pint`

- [ ] **Step 3 : Vérifier migrate:fresh --seed**

Run: `./vendor/bin/sail artisan migrate:fresh --seed`

- [ ] **Step 4 : Commit final si corrections**

```bash
git commit -m "style: apply Pint formatting"
```
