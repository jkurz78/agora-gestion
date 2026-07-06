# Fiche campagne questionnaire — Plan d'implémentation

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal :** Donner une page pivot à chaque campagne de questionnaire (fiche à 4 onglets), épurer la liste, réparer les breadcrumbs/onglets, et corriger le bug des scans inversés (lien soumission↔scan persisté).

**Architecture :** Nouveau composant Livewire `CampagneShow` (onglets `#[Url]` suivi/diffusion/scans/resultats) qui absorbe les actions de ligne et la modale participants d'`OperationQuestionnaires` et embarque les composants existants `ScanUpload` et `CampagneResultats`. Anciennes routes résultats/scans → redirections. Layout : 3ᵉ niveau de breadcrumb. Bug scans : colonne `paper_scan_id` sur `questionnaire_submissions`, mapping direct, suppression de l'appariement positionnel.

**Spec :** `docs/specs/2026-07-06-questionnaires-fiche-campagne.md` (D1-D11, AC1-AC12).

**Environnement :** `./vendor/bin/sail up -d` actif. Tests `./vendor/bin/sail pest <chemin>`. Lint `./vendor/bin/sail bin pint --dirty`. Migration : `./vendor/bin/sail artisan migrate` (MySQL dev). Commits avec trailer `Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>`.

**Conventions :** `declare(strict_types=1)`, `final class`, type hints, locale fr, cast `(int)` des deux côtés des comparaisons PK/FK, `wire:confirm` (modale Bootstrap), en-têtes de tableaux `table-dark` + `style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880"`.

---

### Task 1 : Fix scans inversés — lien persisté `paper_scan_id` (TDD)

**Files:**
- Create: `database/migrations/2026_07_06_100001_add_paper_scan_id_to_questionnaire_submissions.php`
- Modify: `app/Models/QuestionnaireSubmission.php` (fillable)
- Modify: `app/Services/Questionnaire/QuestionnaireReponseService.php` (`creerDepuisOcr`, `creerDepuisOcrAnonyme`)
- Modify: `app/Livewire/Questionnaire/AssistantSaisie.php` (`valider()`)
- Modify: `app/Livewire/Questionnaire/CampagneResultats.php` (mapping)
- Test: `tests/Feature/Questionnaire/ResultatsScanMappingTest.php` (nouveau)

- [ ] **Step 1 : Lire les fichiers à modifier**

Lire `QuestionnaireReponseService.php` (méthodes `creerDepuisOcr` ~l.316 et `creerDepuisOcrAnonyme` ~l.383 : repérer le `->create([...])` de la soumission dans chacune), `QuestionnaireSubmission.php` (fillable/guarded), et `CampagneResultats.php` en entier.

- [ ] **Step 2 : Écrire les tests qui échouent**

Créer `tests/Feature/Questionnaire/ResultatsScanMappingTest.php` :

```php
<?php

declare(strict_types=1);

use App\Livewire\Questionnaire\CampagneResultats;
use App\Models\Operation;
use App\Models\QuestionnaireBearerToken;
use App\Models\QuestionnaireCampaign;
use App\Models\QuestionnaireCampaignQuestion;
use App\Models\QuestionnairePaperScan;
use App\Services\Questionnaire\QuestionnaireReponseService;
use App\Tenant\TenantContext;
use Livewire\Livewire;

function campagneAvecQuestionTexte(): array
{
    $op = Operation::factory()->create();
    $campagne = QuestionnaireCampaign::factory()->for($op, 'operation')->create(['statut' => 'ouverte', 'anonymise' => true]);
    $question = QuestionnaireCampaignQuestion::factory()->for($campagne, 'campaign')->create([
        'libelle' => 'Avis', 'type' => 'texte_court', 'ordre' => 1,
    ]);
    $bearer = QuestionnaireBearerToken::create([
        'association_id' => TenantContext::currentId(),
        'campaign_id' => (int) $campagne->id,
        'token_hash' => hash('sha256', 'bearer-'.uniqid()),
    ]);

    return [$campagne, $question, $bearer];
}

function scanTraite(QuestionnaireCampaign $campagne, QuestionnaireBearerToken $bearer, string $fichier): QuestionnairePaperScan
{
    return QuestionnairePaperScan::create([
        'association_id' => TenantContext::currentId(),
        'campaign_id' => (int) $campagne->id,
        'bearer_token_id' => (int) $bearer->id,
        'source' => 'upload',
        'chemin_fichier' => 'questionnaire-scans/'.$fichier,
        'qr_statut' => 'detecte',
        'statut' => 'traite',
    ]);
}

it('persiste le lien scan sur la soumission créée depuis l assistant OCR', function (): void {
    [$campagne, $question, $bearer] = campagneAvecQuestionTexte();
    $scan = scanTraite($campagne, $bearer, 'scan-a.pdf');

    $submission = app(QuestionnaireReponseService::class)->creerDepuisOcrAnonyme(
        bearer: $bearer,
        valeursParQuestionId: [(string) $question->id => 'Réponse A'],
        paperScanId: (int) $scan->id,
    );

    expect((int) $submission->paper_scan_id)->toBe((int) $scan->id);
});

it('associe chaque réponse papier bearer à SON scan même avec des ordres opposés', function (): void {
    [$campagne, $question, $bearer] = campagneAvecQuestionTexte();
    $service = app(QuestionnaireReponseService::class);

    // Scans créés dans l'ordre A puis B ; soumissions validées dans l'ordre A puis B.
    // La page trie les soumissions par submitted_at DESC : sans lien persisté,
    // l'appariement positionnel inversait A et B (bug de recette 2026-07-06).
    $scanA = scanTraite($campagne, $bearer, 'scan-a.pdf');
    $scanB = scanTraite($campagne, $bearer, 'scan-b.pdf');

    $subA = $service->creerDepuisOcrAnonyme(
        bearer: $bearer,
        valeursParQuestionId: [(string) $question->id => 'Réponse A'],
        paperScanId: (int) $scanA->id,
    );
    $subA->update(['submitted_at' => now()->subMinutes(10)]);

    $subB = $service->creerDepuisOcrAnonyme(
        bearer: $bearer,
        valeursParQuestionId: [(string) $question->id => 'Réponse B'],
        paperScanId: (int) $scanB->id,
    );
    $subB->update(['submitted_at' => now()]);

    Livewire::test(CampagneResultats::class, ['campagne' => $campagne->fresh()])
        ->assertViewHas('scanParSubmission', function ($mapping) use ($subA, $subB, $scanA, $scanB): bool {
            return (int) $mapping[(int) $subA->id]->id === (int) $scanA->id
                && (int) $mapping[(int) $subB->id]->id === (int) $scanB->id;
        });
});

it('n associe aucun scan aux soumissions bearer historiques sans lien', function (): void {
    [$campagne, $question, $bearer] = campagneAvecQuestionTexte();

    scanTraite($campagne, $bearer, 'scan-a.pdf');
    $sub = app(QuestionnaireReponseService::class)->creerDepuisOcrAnonyme(
        bearer: $bearer,
        valeursParQuestionId: [(string) $question->id => 'Réponse'],
    );
    $sub->update(['paper_scan_id' => null]);

    Livewire::test(CampagneResultats::class, ['campagne' => $campagne->fresh()])
        ->assertViewHas('scanParSubmission', fn ($mapping): bool => ! isset($mapping[(int) $sub->id]));
});
```

Note : adapter les colonnes de `QuestionnaireBearerToken::create`/`QuestionnairePaperScan::create` aux
schémas réels si un champ obligatoire manque (lire les migrations `*questionnaire_paper*` et le modèle) —
sans changer le sens des tests.

- [ ] **Step 3 : Vérifier l'échec**

Run : `./vendor/bin/sail pest tests/Feature/Questionnaire/ResultatsScanMappingTest.php`
Attendu : ÉCHEC — `Unknown named parameter $paperScanId` (test 1) et/ou colonne inconnue.

- [ ] **Step 4 : Implémenter**

1. Migration :

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('questionnaire_submissions', function (Blueprint $table): void {
            $table->foreignId('paper_scan_id')->nullable()
                ->constrained('questionnaire_paper_scans')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('questionnaire_submissions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('paper_scan_id');
        });
    }
};
```

Puis `./vendor/bin/sail artisan migrate`.

2. `QuestionnaireSubmission` : ajouter `'paper_scan_id'` au `$fillable` (s'il existe).

3. `QuestionnaireReponseService` : ajouter le paramètre `?int $paperScanId = null` en dernière
position de `creerDepuisOcr()` ET `creerDepuisOcrAnonyme()` ; dans chaque `->create([...])` de la
soumission, ajouter `'paper_scan_id' => $paperScanId,`. Ne rien changer d'autre.

4. `AssistantSaisie::valider()` : passer `paperScanId: (int) $this->scan->id` aux deux appels
(`creerDepuisOcr` et `creerDepuisOcrAnonyme`).

5. `CampagneResultats::render()` : remplacer intégralement le bloc de construction de
`$scanParSubmission` (lignes ~49-61) par :

```php
        $scanParSubmission = collect();
        $scansParId = $scans->keyBy(fn ($s) => (int) $s->id);
        $scansParInvitation = $scans->whereNotNull('invitation_id')->keyBy(fn ($s) => (int) $s->invitation_id);

        foreach ($submissions->where('source', 'papier') as $sub) {
            if ($sub->paper_scan_id !== null && $scansParId->has((int) $sub->paper_scan_id)) {
                $scanParSubmission[(int) $sub->id] = $scansParId->get((int) $sub->paper_scan_id);
            } elseif ($sub->invitation_id !== null && $scansParInvitation->has((int) $sub->invitation_id)) {
                // Repli fiable pour l'historique d'avant paper_scan_id (scans nominatifs)
                $scanParSubmission[(int) $sub->id] = $scansParInvitation->get((int) $sub->invitation_id);
            }
            // Bearer historique sans lien : pas de badge scan (jamais d'appariement positionnel)
        }
```

- [ ] **Step 5 : Vérifier**

Run : `./vendor/bin/sail pest tests/Feature/Questionnaire/ResultatsScanMappingTest.php tests/Feature/Questionnaire/QuestionnaireReponseServiceTest.php tests/Feature/Questionnaire/QuestionnaireScanServiceTest.php`
Attendu : tout vert.

- [ ] **Step 6 : Commit**

```bash
git add -A && git commit -m "fix(questionnaires): lien persisté soumission-scan, fin de l'appariement positionnel"
```

---

### Task 2 : Layout — 3ᵉ niveau de breadcrumb + onglet opération deep-linkable

**Files:**
- Modify: `resources/views/layouts/app-sidebar.blade.php` (~l.171)
- Modify: `app/Livewire/OperationDetail.php`
- Test: `tests/Feature/Questionnaire/FicheCampagneNavigationTest.php` (nouveau, sera enrichi en Task 3)

- [ ] **Step 1 : Test qui échoue**

Créer `tests/Feature/Questionnaire/FicheCampagneNavigationTest.php` :

```php
<?php

declare(strict_types=1);

use App\Livewire\OperationDetail;
use App\Models\Operation;
use Livewire\Livewire;

it('ouvre l onglet questionnaires de la fiche opération via ?tab=', function (): void {
    $op = Operation::factory()->create();

    Livewire::withQueryParams(['tab' => 'questionnaires'])
        ->test(OperationDetail::class, ['operation' => $op])
        ->assertSet('activeTab', 'questionnaires');
});
```

Run : `./vendor/bin/sail pest tests/Feature/Questionnaire/FicheCampagneNavigationTest.php`
Attendu : ÉCHEC (`activeTab` reste `participants`). Si `OperationDetail::mount()` gère déjà un
paramètre `$tab`, vérifier pourquoi le query param `tab` n'est pas pris et adapter le test
UNIQUEMENT si le comportement attendu est déjà couvert autrement (improbable).

- [ ] **Step 2 : Implémenter**

1. `OperationDetail` : ajouter `use Livewire\Attributes\Url;` et l'attribut sur la propriété :

```php
    #[Url(as: 'tab')]
    public string $activeTab = 'participants';
```

Lire `mount()` : s'il écrase `$activeTab`, préserver la valeur venant de l'URL (n'écraser que si la
valeur n'est pas un onglet connu).

2. `app-sidebar.blade.php` : insérer juste AVANT le bloc `@if(isset($breadcrumbGrandParent))` (~l.171) :

```blade
                        @if(isset($breadcrumbGreatGrandParent))
                            <li class="breadcrumb-item">
                                <a href="{{ $breadcrumbGreatGrandParent->attributes['url'] }}" style="color: rgba(255,255,255,.6); text-decoration:none;">{{ $breadcrumbGreatGrandParent }}</a>
                            </li>
                        @endif
```

- [ ] **Step 3 : Vérifier + commit**

Run : `./vendor/bin/sail pest tests/Feature/Questionnaire/FicheCampagneNavigationTest.php` → PASS.

```bash
git add -A && git commit -m "feat(nav): breadcrumb 3 niveaux + onglet opération deep-linkable (?tab=)"
```

---

### Task 3 : `CampagneShow` — fiche campagne avec onglets

**Files:**
- Create: `app/Livewire/Questionnaire/CampagneShow.php`
- Create: `resources/views/questionnaire/campagnes/show.blade.php` (host page)
- Create: `resources/views/livewire/questionnaire/campagne-show.blade.php`
- Modify: `routes/web.php` (route `campagnes.show`)
- Test: `tests/Feature/Questionnaire/CampagneShowTest.php` (nouveau)

- [ ] **Step 1 : Tests qui échouent**

Créer `tests/Feature/Questionnaire/CampagneShowTest.php` :

```php
<?php

declare(strict_types=1);

use App\Enums\StatutCampagne;
use App\Livewire\Questionnaire\CampagneShow;
use App\Models\Operation;
use App\Models\Participant;
use App\Models\QuestionnaireCampaign;
use App\Models\User;
use Livewire\Livewire;

function campagnePourFiche(string $statut = 'ouverte'): QuestionnaireCampaign
{
    $op = Operation::factory()->create();

    return QuestionnaireCampaign::factory()->for($op, 'operation')->create(['statut' => $statut]);
}

it('affiche la fiche sur l onglet suivi par défaut', function (): void {
    $campagne = campagnePourFiche();

    $this->actingAs(User::factory()->create())
        ->get(route('questionnaires.campagnes.show', $campagne))
        ->assertOk()
        ->assertSee($campagne->titre_affiche ?: $campagne->titre)
        ->assertSeeLivewire(CampagneShow::class);
});

it('ouvre l onglet demandé par ?tab=', function (): void {
    $campagne = campagnePourFiche();

    Livewire::withQueryParams(['tab' => 'resultats'])
        ->test(CampagneShow::class, ['campagne' => $campagne])
        ->assertSet('tab', 'resultats');
});

it('retombe sur suivi pour un onglet inconnu', function (): void {
    $campagne = campagnePourFiche();

    Livewire::withQueryParams(['tab' => 'nimporte'])
        ->test(CampagneShow::class, ['campagne' => $campagne])
        ->assertSet('tab', 'suivi');
});

it('lance une campagne brouillon depuis l en-tête', function (): void {
    $campagne = campagnePourFiche('brouillon');

    Livewire::test(CampagneShow::class, ['campagne' => $campagne])
        ->call('ouvrir');

    expect($campagne->fresh()->statut)->toBe(StatutCampagne::Ouverte);
});

it('clôture une campagne ouverte depuis l en-tête', function (): void {
    $campagne = campagnePourFiche();

    Livewire::test(CampagneShow::class, ['campagne' => $campagne])
        ->call('cloturer');

    expect($campagne->fresh()->statut)->toBe(StatutCampagne::Cloturee);
});

it('importe un scan ciblé pour une invitation depuis l onglet suivi', function (): void {
    $campagne = campagnePourFiche();
    $participant = Participant::factory()->create(['operation_id' => $campagne->operation_id]);
    $invitation = $campagne->invitations()->create([
        'association_id' => $campagne->association_id,
        'participant_id' => (int) $participant->id,
        'token_hash' => hash('sha256', 'tok-'.uniqid()),
        'token_chiffre' => 'tok',
        'code_court' => strtoupper(substr(md5(uniqid()), 0, 8)),
        'statut' => \App\Enums\StatutInvitation::NonOuvert,
    ]);

    Livewire::test(CampagneShow::class, ['campagne' => $campagne])
        ->call('ouvrirScanPour', (int) $invitation->id)
        ->assertSet('scanPourInvitationId', (int) $invitation->id);
});
```

Ajouter aussi le smoke cross-tenant (AC11) — s'inspirer du pattern des tests d'intrusion existants
(`grep -rn "autre association\|cross-tenant" tests/Feature/Questionnaire tests/Feature/Intrusion`)
pour créer une campagne sous une AUTRE association et vérifier que
`GET route('questionnaires.campagnes.show', $campagneAutreTenant)` répond **404** pour l'utilisateur
du tenant courant (TenantScope + route model binding).

(S'inspirer de `QuestionnaireScanServiceTest` pour les colonnes exactes d'invitation si un champ
obligatoire diffère.)

Run : `./vendor/bin/sail pest tests/Feature/Questionnaire/CampagneShowTest.php`
Attendu : ÉCHEC — route/classe inexistantes.

- [ ] **Step 2 : Implémenter le composant**

Créer `app/Livewire/Questionnaire/CampagneShow.php` :

```php
<?php

declare(strict_types=1);

namespace App\Livewire\Questionnaire;

use App\Models\QuestionnaireCampaign;
use App\Models\QuestionnaireInvitation;
use App\Services\Questionnaire\QuestionnaireCampaignService;
use App\Services\Questionnaire\QuestionnaireReponseService;
use App\Services\Questionnaire\QuestionnaireScanService;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

final class CampagneShow extends Component
{
    use WithFileUploads;

    private const ONGLETS = ['suivi', 'diffusion', 'scans', 'resultats'];

    public QuestionnaireCampaign $campagne;

    #[Url(as: 'tab')]
    public string $tab = 'suivi';

    public ?int $scanPourInvitationId = null;

    /** @var TemporaryUploadedFile|null */
    public $scanFichier = null;

    public function mount(QuestionnaireCampaign $campagne): void
    {
        $this->campagne = $campagne;
        if (! in_array($this->tab, self::ONGLETS, true)) {
            $this->tab = 'suivi';
        }
    }

    public function setTab(string $tab): void
    {
        if (in_array($tab, self::ONGLETS, true)) {
            $this->tab = $tab;
            $this->scanPourInvitationId = null;
            $this->reset('scanFichier');
        }
    }

    public function render(): View
    {
        $this->campagne->loadCount([
            'invitations',
            'submissions as soumises_count' => fn ($q) => $q->where('statut', 'soumise'),
        ]);

        $invitations = $this->campagne->invitations()
            ->with('participant.tiers')
            ->get()
            ->sortBy(fn ($i) => $i->participant?->tiers?->nom)
            ->values();

        $scansATraiter = $this->campagne->paperScans()
            ->whereHas('ocrDraft', fn ($q) => $q->where('statut', 'brouillon'))
            ->count();

        return view('livewire.questionnaire.campagne-show', [
            'invitations' => $invitations,
            'scansATraiter' => $scansATraiter,
        ]);
    }

    public function ouvrir(QuestionnaireCampaignService $campagnes): void
    {
        $campagnes->ouvrir($this->campagne);
        $this->campagne->refresh();
    }

    public function cloturer(QuestionnaireCampaignService $campagnes): void
    {
        $campagnes->cloturer($this->campagne);
        $this->campagne->refresh();
    }

    public function rouvrirInvitation(int $invitationId, QuestionnaireReponseService $reponses): void
    {
        $reponses->rouvrir($this->invitation($invitationId));
    }

    public function ouvrirScanPour(int $invitationId): void
    {
        $this->scanPourInvitationId = (int) $this->invitation($invitationId)->id;
        $this->reset('scanFichier');
    }

    public function importerScanPour(QuestionnaireScanService $scanService): void
    {
        $this->validate(['scanFichier' => 'required|file|mimes:png,jpg,jpeg,pdf|max:10240']);

        $invitation = $this->invitation((int) $this->scanPourInvitationId);
        $scanService->ingererPourInvitation($this->scanFichier, $invitation);

        $this->scanPourInvitationId = null;
        $this->reset('scanFichier');
        session()->flash('scan_ok', 'Scan importé et attribué au participant.');
    }

    private function invitation(int $id): QuestionnaireInvitation
    {
        return $this->campagne->invitations()->findOrFail($id);
    }
}
```

Note : vérifier le nom de la relation scans sur `QuestionnaireCampaign` (`paperScans()` supposé —
lire le modèle ; si elle n'existe pas, l'ajouter : `hasMany(QuestionnairePaperScan::class, 'campaign_id')`).

- [ ] **Step 3 : Host page + route**

Créer `resources/views/questionnaire/campagnes/show.blade.php` :

```blade
<x-app-layout>
    <x-slot:breadcrumbGreatGrandParent url="{{ route('operations.index') }}">Liste des opérations</x-slot:breadcrumbGreatGrandParent>
    <x-slot:breadcrumbGrandParent url="{{ route('operations.show', $campagne->operation_id) }}?tab=questionnaires">{{ $campagne->operation->nom }}</x-slot:breadcrumbGrandParent>
    <x-slot:title>{{ $campagne->titre_affiche ?: $campagne->titre }}</x-slot:title>
    <livewire:questionnaire.campagne-show :campagne="$campagne" :key="'fiche-'.$campagne->id" />
</x-app-layout>
```

(Pas de `breadcrumbParent` : la fiche est le niveau courant. Titre = nom du questionnaire.)

Route dans `routes/web.php`, groupe questionnaires, APRÈS les routes `campagnes/{campagne}/...`
existantes :

```php
        Route::get('/campagnes/{campagne}', function (QuestionnaireCampaign $campagne) {
            return view('questionnaire.campagnes.show', compact('campagne'));
        })->name('campagnes.show');
```

- [ ] **Step 4 : Blade du composant**

Créer `resources/views/livewire/questionnaire/campagne-show.blade.php` :

```blade
<div>
    @php
        $badgeClass = match ($campagne->statut) {
            \App\Enums\StatutCampagne::Brouillon  => 'bg-secondary',
            \App\Enums\StatutCampagne::Ouverte    => 'bg-success',
            \App\Enums\StatutCampagne::Cloturee   => 'bg-warning text-dark',
            \App\Enums\StatutCampagne::Archivee   => 'bg-dark',
        };
    @endphp

    <div class="d-flex justify-content-between align-items-start mb-3">
        <div>
            <h1 class="h4 mb-1">
                {{ $campagne->titre_affiche ?: $campagne->titre }}
                <span class="badge {{ $badgeClass }} ms-2 align-middle">{{ $campagne->statut->label() }}</span>
            </h1>
            <div class="text-muted small">
                Créée le {{ $campagne->created_at->format('d/m/Y') }}
                — opération {{ $campagne->operation->nom }}
            </div>
        </div>
        <div class="d-flex gap-2">
            @if ($campagne->statut->peutOuvrir())
                <button class="btn btn-sm btn-outline-success"
                        wire:click="ouvrir"
                        wire:confirm="Lancer cette campagne ? Les participants pourront répondre.">
                    <i class="bi bi-play-fill me-1"></i>Lancer
                </button>
            @endif
            @if ($campagne->statut->peutCloturer())
                <button class="btn btn-sm btn-outline-warning"
                        wire:click="cloturer"
                        wire:confirm="Clôturer cette campagne ? Les réponses ne seront plus acceptées.">
                    <i class="bi bi-lock me-1"></i>Clôturer
                </button>
            @endif
        </div>
    </div>

    @if (session('scan_ok'))
        <div class="alert alert-success py-2 small">{{ session('scan_ok') }}</div>
    @endif

    <ul class="nav nav-tabs mb-3">
        <li class="nav-item">
            <button class="nav-link {{ $tab === 'suivi' ? 'active' : '' }}" wire:click="setTab('suivi')">
                <i class="bi bi-people me-1"></i>Suivi
            </button>
        </li>
        <li class="nav-item">
            <button class="nav-link {{ $tab === 'diffusion' ? 'active' : '' }}" wire:click="setTab('diffusion')">
                <i class="bi bi-envelope me-1"></i>Diffusion
            </button>
        </li>
        <li class="nav-item">
            <button class="nav-link {{ $tab === 'scans' ? 'active' : '' }}" wire:click="setTab('scans')">
                <i class="bi bi-qr-code-scan me-1"></i>Scans
                @if ($scansATraiter > 0)
                    <span class="badge bg-primary ms-1">{{ $scansATraiter }}</span>
                @endif
            </button>
        </li>
        <li class="nav-item">
            <button class="nav-link {{ $tab === 'resultats' ? 'active' : '' }}" wire:click="setTab('resultats')">
                <i class="bi bi-bar-chart me-1"></i>Résultats
            </button>
        </li>
    </ul>

    @if ($tab === 'suivi')
        <div class="row g-3 mb-3">
            <div class="col-md-4">
                <div class="card"><div class="card-body py-2">
                    <div class="text-muted small">Invités</div>
                    <div class="h4 mb-0">{{ $campagne->invitations_count }}</div>
                </div></div>
            </div>
            <div class="col-md-4">
                <div class="card"><div class="card-body py-2">
                    <div class="text-muted small">Réponses</div>
                    <div class="h4 mb-0">{{ $campagne->soumises_count }}</div>
                </div></div>
            </div>
            <div class="col-md-4">
                <div class="card"><div class="card-body py-2">
                    <div class="text-muted small">Taux de réponse</div>
                    <div class="h4 mb-0">
                        @if ($campagne->invitations_count > 0)
                            {{ round($campagne->soumises_count / $campagne->invitations_count * 100) }} %
                        @else
                            —
                        @endif
                    </div>
                </div></div>
            </div>
        </div>

        <table class="table table-sm table-hover align-middle">
            <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
                <tr>
                    <th class="ps-3">Participant</th>
                    <th class="text-center">Statut</th>
                    <th class="text-end pe-3">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($invitations as $inv)
                    @php
                        $statutBadge = match ($inv->statut) {
                            \App\Enums\StatutInvitation::Soumis    => ['bg-success', 'Soumis'],
                            \App\Enums\StatutInvitation::Commence  => ['bg-info', 'En cours'],
                            \App\Enums\StatutInvitation::NonOuvert => ['bg-secondary', 'Non ouvert'],
                            default                                => ['bg-secondary', $inv->statut->value],
                        };
                    @endphp
                    <tr>
                        <td class="ps-3">{{ $inv->participant?->tiers?->displayName() ?? '—' }}</td>
                        <td class="text-center">
                            <span class="badge {{ $statutBadge[0] }}">{{ $statutBadge[1] }}</span>
                        </td>
                        <td class="text-end pe-3">
                            @if ($inv->statut !== \App\Enums\StatutInvitation::Soumis && $campagne->statut === \App\Enums\StatutCampagne::Ouverte)
                                <a href="{{ $inv->lienReponse() . (str_contains($inv->lienReponse(), '?') ? '&' : '?') . 'saisie_pour=1' }}"
                                   target="_blank"
                                   class="btn btn-sm btn-outline-primary me-1"
                                   title="Remplir le formulaire en ligne">
                                    <i class="bi bi-pencil-square me-1"></i>Saisir
                                </a>
                                <button class="btn btn-sm btn-outline-dark"
                                        wire:click="ouvrirScanPour({{ $inv->id }})"
                                        title="Importer un scan pour ce participant">
                                    <i class="bi bi-camera me-1"></i>Scanner
                                </button>
                            @endif
                            @if ($inv->statut === \App\Enums\StatutInvitation::Soumis && !$campagne->anonymise)
                                <button class="btn btn-sm btn-outline-secondary"
                                        wire:click="rouvrirInvitation({{ $inv->id }})"
                                        wire:confirm="Rouvrir cette réponse ?">
                                    <i class="bi bi-arrow-counterclockwise me-1"></i>Rouvrir
                                </button>
                            @endif
                        </td>
                    </tr>
                    @if ($scanPourInvitationId === (int) $inv->id)
                        <tr class="table-light">
                            <td colspan="3" class="ps-4 pe-3 py-2">
                                <div class="d-flex align-items-center gap-2">
                                    <input type="file"
                                           wire:model="scanFichier"
                                           accept=".png,.jpg,.jpeg,.pdf"
                                           class="form-control form-control-sm" style="max-width:300px">
                                    <button class="btn btn-sm btn-primary"
                                            wire:click="importerScanPour"
                                            @if(!$scanFichier) disabled @endif>
                                        <i class="bi bi-upload me-1"></i>Importer
                                    </button>
                                    <button class="btn btn-sm btn-outline-secondary"
                                            wire:click="$set('scanPourInvitationId', null)">
                                        Annuler
                                    </button>
                                    <div wire:loading wire:target="scanFichier" class="spinner-border spinner-border-sm text-primary"></div>
                                </div>
                            </td>
                        </tr>
                    @endif
                @empty
                    <tr><td colspan="3" class="text-muted text-center py-4">Aucune invitation.</td></tr>
                @endforelse
            </tbody>
        </table>
    @endif

    @if ($tab === 'diffusion')
        <div class="row g-3">
            <div class="col-md-4">
                <div class="card h-100"><div class="card-body">
                    <h2 class="h6"><i class="bi bi-envelope me-1"></i>Invitations par email</h2>
                    <p class="text-muted small mb-2">Composer et envoyer les invitations ou les relances.</p>
                    <a href="{{ route('questionnaires.campagnes.envoi', $campagne) }}" class="btn btn-sm btn-primary">
                        Envoyer les invitations
                    </a>
                </div></div>
            </div>
            <div class="col-md-4">
                <div class="card h-100"><div class="card-body">
                    <h2 class="h6"><i class="bi bi-printer me-1"></i>Papier</h2>
                    <p class="text-muted small mb-2">Imprimer le questionnaire à remplir à la main (QR de réponse en ligne inclus).</p>
                    @if ($campagne->anonymise)
                        <a href="{{ route('questionnaires.campagnes.pdf-anonyme', $campagne) }}" target="_blank" class="btn btn-sm btn-outline-primary">
                            Imprimer (anonyme)
                        </a>
                    @else
                        <a href="{{ route('questionnaires.campagnes.pdf', $campagne) }}" target="_blank" class="btn btn-sm btn-outline-primary">
                            PDF papier
                        </a>
                    @endif
                </div></div>
            </div>
            <div class="col-md-4">
                <div class="card h-100"><div class="card-body">
                    <h2 class="h6"><i class="bi bi-eye me-1"></i>Aperçu</h2>
                    <p class="text-muted small mb-2">Voir le questionnaire comme un répondant, sans rien enregistrer.</p>
                    <a href="{{ route('questionnaires.campagnes.apercu', $campagne) }}" target="_blank" class="btn btn-sm btn-outline-secondary">
                        Aperçu répondant
                    </a>
                </div></div>
            </div>
        </div>
    @endif

    @if ($tab === 'scans')
        <livewire:questionnaire.scan-upload :campagne="$campagne" :key="'scans-'.$campagne->id" />
    @endif

    @if ($tab === 'resultats')
        <livewire:questionnaire.campagne-resultats :campagne="$campagne" :key="'resultats-'.$campagne->id" />
    @endif
</div>
```

- [ ] **Step 5 : Vérifier + commit**

Run : `./vendor/bin/sail pest tests/Feature/Questionnaire/CampagneShowTest.php` → PASS (6 tests).

```bash
git add -A && git commit -m "feat(questionnaires): fiche campagne à onglets (CampagneShow)"
```

---

### Task 4 : Redirections des anciennes routes + breadcrumbs des écrans profonds

**Files:**
- Modify: `routes/web.php` (routes `campagnes.resultats` et `campagnes.scans` → redirect)
- Delete: `resources/views/questionnaire/resultats/index.blade.php`, `resources/views/questionnaire/scans/index.blade.php` (host pages devenues mortes)
- Modify: `resources/views/questionnaire/campagnes/envoi.blade.php`, `resources/views/questionnaire/scans/valider.blade.php` (breadcrumbs 3 niveaux)
- Test: `tests/Feature/Questionnaire/FicheCampagneNavigationTest.php` (ajouts)

- [ ] **Step 1 : Tests qui échouent** (à ajouter à `FicheCampagneNavigationTest.php`)

```php
it('redirige l ancienne route résultats vers la fiche', function (): void {
    $op = Operation::factory()->create();
    $campagne = \App\Models\QuestionnaireCampaign::factory()->for($op, 'operation')->create(['statut' => 'ouverte']);

    $this->actingAs(\App\Models\User::factory()->create())
        ->get(route('questionnaires.campagnes.resultats', $campagne))
        ->assertRedirect(route('questionnaires.campagnes.show', ['campagne' => $campagne, 'tab' => 'resultats']));
});

it('redirige l ancienne route scans vers la fiche', function (): void {
    $op = Operation::factory()->create();
    $campagne = \App\Models\QuestionnaireCampaign::factory()->for($op, 'operation')->create(['statut' => 'ouverte']);

    $this->actingAs(\App\Models\User::factory()->create())
        ->get(route('questionnaires.campagnes.scans', $campagne))
        ->assertRedirect(route('questionnaires.campagnes.show', ['campagne' => $campagne, 'tab' => 'scans']));
});
```

Attention : d'autres tests existants (ex. `AssistantSaisie` redirige vers `campagnes.scans`) suivront
la redirection — les adapter s'ils assertent le contenu de l'ancienne page (préférer
`assertRedirect(route('questionnaires.campagnes.scans', ...))` qui reste vrai).

- [ ] **Step 2 : Implémenter**

1. `routes/web.php` — remplacer les deux closures :

```php
        Route::get('/campagnes/{campagne}/resultats', function (QuestionnaireCampaign $campagne) {
            return redirect()->route('questionnaires.campagnes.show', ['campagne' => $campagne, 'tab' => 'resultats']);
        })->name('campagnes.resultats');
```

```php
        Route::get('/campagnes/{campagne}/scans', function (QuestionnaireCampaign $campagne) {
            return redirect()->route('questionnaires.campagnes.show', ['campagne' => $campagne, 'tab' => 'scans']);
        })->name('campagnes.scans');
```

2. Supprimer `resources/views/questionnaire/resultats/index.blade.php` et
`resources/views/questionnaire/scans/index.blade.php` (ne PAS toucher `consolides.blade.php`,
`pdf.blade.php`, `_resultats.blade.php`, `_reponses-individuelles.blade.php`).

3. `envoi.blade.php` — breadcrumbs :

```blade
    <x-slot:breadcrumbGreatGrandParent url="{{ route('operations.index') }}">Liste des opérations</x-slot:breadcrumbGreatGrandParent>
    <x-slot:breadcrumbGrandParent url="{{ route('operations.show', $campagne->operation_id) }}?tab=questionnaires">{{ $campagne->operation->nom }}</x-slot:breadcrumbGrandParent>
    <x-slot:breadcrumbParent url="{{ route('questionnaires.campagnes.show', $campagne) }}">{{ $campagne->titre_affiche ?: $campagne->titre }}</x-slot:breadcrumbParent>
    <x-slot:title>Invitations</x-slot:title>
```

4. `scans/valider.blade.php` — breadcrumbs :

```blade
    <x-slot:breadcrumbGreatGrandParent url="{{ route('operations.show', $campagne->operation_id) }}?tab=questionnaires">{{ $campagne->operation->nom }}</x-slot:breadcrumbGreatGrandParent>
    <x-slot:breadcrumbGrandParent url="{{ route('questionnaires.campagnes.show', $campagne) }}">{{ $campagne->titre_affiche ?: $campagne->titre }}</x-slot:breadcrumbGrandParent>
    <x-slot:breadcrumbParent url="{{ route('questionnaires.campagnes.show', ['campagne' => $campagne, 'tab' => 'scans']) }}">Scans</x-slot:breadcrumbParent>
    <x-slot:title>Validation OCR</x-slot:title>
```

- [ ] **Step 3 : Vérifier + commit**

Run : `./vendor/bin/sail pest tests/Feature/Questionnaire/FicheCampagneNavigationTest.php tests/Feature/Questionnaire/QuestionnaireRoutesSmokeTest.php`
Attendu : PASS (adapter les smoke tests qui attendaient un 200 sur resultats/scans : ils attendent
désormais un 302).

```bash
git add -A && git commit -m "feat(questionnaires): redirections vers la fiche + breadcrumbs 3 niveaux"
```

---

### Task 5 : Épurer l'onglet Questionnaires de l'opération

**Files:**
- Modify: `app/Livewire/Questionnaire/OperationQuestionnaires.php`
- Modify: `resources/views/livewire/questionnaire/operation-questionnaires.blade.php`
- Test: adapter les tests existants qui exerçaient les méthodes déplacées

- [ ] **Step 1 : Lire les tests existants**

`grep -rn "OperationQuestionnaires" tests/` — repérer les tests qui appellent `ouvrir`, `cloturer`,
`rouvrirInvitation`, `ouvrirParticipants`, `ouvrirScanPour`, `importerScanPour` sur ce composant.
Ces comportements sont maintenant portés par `CampagneShow` (déjà testés en Task 3) : migrer les
assertions restantes utiles vers `CampagneShowTest`, supprimer les tests devenus sans objet.

- [ ] **Step 2 : Implémenter**

1. `OperationQuestionnaires.php` : supprimer les propriétés `showParticipantsCampagneId`,
`scanPourInvitationId`, `scanFichier`, le trait `WithFileUploads`, et les méthodes
`ouvrirParticipants`, `fermerParticipants`, `ouvrirScanPour`, `importerScanPour`, `ouvrir`,
`cloturer`, `rouvrirInvitation`, `campagne()`. Nettoyer les imports morts. `creerCampagne()` se
termine désormais par :

```php
        $this->redirect(route('questionnaires.campagnes.show', $campagne));
```

Dans `render()`, supprimer le calcul de `$campagneModale` et l'eager-load `invitations.participant.tiers`
(la liste n'affiche plus que des compteurs).

2. Blade `operation-questionnaires.blade.php` :
   - Cellule Titre → lien vers la fiche :

```blade
                    <td>
                        <a href="{{ route('questionnaires.campagnes.show', $c) }}" class="fw-semibold text-decoration-none">
                            {{ $c->titre_affiche ?: $c->titre }}
                        </a>
                        <span class="text-muted small ms-1">({{ $c->created_at->format('d/m/Y') }})</span>
                    </td>
```

   - Colonne Participants → compteur simple `{{ $c->invitations_count }}` (sans bouton).
   - Supprimer la colonne Actions (`<th>` + `<td>` entiers) et toute la « Modale Participants »
     (bloc `@if ($campagneModale !== null) … @endif`).
   - Conserver « + Nouvelle campagne », « Consolider », la modale de création, l'alerte `scan_ok`.

- [ ] **Step 3 : Vérifier + commit**

Run : `./vendor/bin/sail pest tests/Feature/Questionnaire` → 0 failed.

```bash
git add -A && git commit -m "feat(questionnaires): liste épurée, actions déplacées dans la fiche campagne"
```

---

### Task 6 : Lint + suite complète + vérification navigateur

- [ ] **Step 1 :** `./vendor/bin/sail bin pint --dirty` → propre.
- [ ] **Step 2 :** `./vendor/bin/sail pest tests/Feature/Questionnaire tests/Unit/Questionnaire` → 0 failed.
- [ ] **Step 3 :** Commit final éventuel (`style(questionnaires): pint`).
- [ ] **Step 4 :** Signaler que la recette navigateur (AC1-AC8 visuels) est à faire par l'utilisateur.
