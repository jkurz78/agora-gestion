# Nouveaux types de questions — Plan d'implémentation

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ajouter 5 nouveaux types de questions au moteur questionnaires : Date, Choix multiple, Nombre, Email, Sélection numérique.

**Architecture:** Chaque type suit le même pattern — une case dans l'enum `TypeQuestion`, un match arm dans chaque service (normalisation, agrégation, export, OCR), un `@case` dans chaque blade (formulaire web, PDF papier, résultats). Aucune migration DB sur `questionnaire_answers` — on réutilise les colonnes existantes.

**Tech Stack:** Laravel 11, Livewire 4, Bootstrap 5, Pest PHP, DomPDF.

**Spec :** `docs/specs/2026-07-07-questionnaires-nouveaux-types.md`

---

### Task 1 : Enum TypeQuestion + tests unitaires

**Files :**
- Modify: `app/Enums/TypeQuestion.php`
- Modify: `tests/Unit/Enums/TypeQuestionTest.php`

- [ ] **Step 1 : Écrire les tests pour les 5 nouveaux types**

Ajouter dans `tests/Unit/Enums/TypeQuestionTest.php` :

```php
// ── Date ────────────────────────────────────────
it('Date a le bon libellé', function (): void {
    expect(TypeQuestion::Date->label())->toBe('Date');
});

it('Date stocke dans value_text', function (): void {
    expect(TypeQuestion::Date->valueColumn())->toBe('value_text');
});

it('Date est un type réponse sans options', function (): void {
    expect(TypeQuestion::Date->estReponse())->toBeTrue();
    expect(TypeQuestion::Date->aDesOptions())->toBeFalse();
});

// ── ChoixMultiple ────────────────────────────────
it('ChoixMultiple a le bon libellé', function (): void {
    expect(TypeQuestion::ChoixMultiple->label())->toBe('Choix multiple');
});

it('ChoixMultiple stocke dans value_option', function (): void {
    expect(TypeQuestion::ChoixMultiple->valueColumn())->toBe('value_option');
});

it('ChoixMultiple est un type réponse avec options', function (): void {
    expect(TypeQuestion::ChoixMultiple->estReponse())->toBeTrue();
    expect(TypeQuestion::ChoixMultiple->aDesOptions())->toBeTrue();
});

// ── Nombre ───────────────────────────────────────
it('Nombre a le bon libellé', function (): void {
    expect(TypeQuestion::Nombre->label())->toBe('Nombre');
});

it('Nombre stocke dans value_text', function (): void {
    expect(TypeQuestion::Nombre->valueColumn())->toBe('value_text');
});

it('Nombre est un type réponse sans options', function (): void {
    expect(TypeQuestion::Nombre->estReponse())->toBeTrue();
    expect(TypeQuestion::Nombre->aDesOptions())->toBeFalse();
});

// ── Email ────────────────────────────────────────
it('Email a le bon libellé', function (): void {
    expect(TypeQuestion::Email->label())->toBe('Adresse email');
});

it('Email stocke dans value_text', function (): void {
    expect(TypeQuestion::Email->valueColumn())->toBe('value_text');
});

it('Email est un type réponse sans options', function (): void {
    expect(TypeQuestion::Email->estReponse())->toBeTrue();
    expect(TypeQuestion::Email->aDesOptions())->toBeFalse();
});

// ── SelectionNumerique ───────────────────────────
it('SelectionNumerique a le bon libellé', function (): void {
    expect(TypeQuestion::SelectionNumerique->label())->toBe('Sélection numérique');
});

it('SelectionNumerique stocke dans value_integer', function (): void {
    expect(TypeQuestion::SelectionNumerique->valueColumn())->toBe('value_integer');
});

it('SelectionNumerique est un type réponse sans options', function (): void {
    expect(TypeQuestion::SelectionNumerique->estReponse())->toBeTrue();
    expect(TypeQuestion::SelectionNumerique->aDesOptions())->toBeFalse();
});
```

- [ ] **Step 2 : Vérifier que les tests échouent**

Run: `./vendor/bin/sail exec -T laravel.test php artisan test --filter="TypeQuestionTest" 2>&1 | tail -20`
Expected: FAIL — les cases `Date`, `ChoixMultiple`, `Nombre`, `Email`, `SelectionNumerique` n'existent pas.

- [ ] **Step 3 : Ajouter les 5 cases à l'enum**

Dans `app/Enums/TypeQuestion.php`, ajouter les 5 cases après `Information` :

```php
case Date = 'date';
case ChoixMultiple = 'choix_multiple';
case Nombre = 'nombre';
case Email = 'email';
case SelectionNumerique = 'selection_numerique';
```

Mettre à jour les méthodes :

`label()` — ajouter :
```php
self::Date => 'Date',
self::ChoixMultiple => 'Choix multiple',
self::Nombre => 'Nombre',
self::Email => 'Adresse email',
self::SelectionNumerique => 'Sélection numérique',
```

`valueColumn()` — ajouter :
```php
self::Date, self::Nombre, self::Email => 'value_text',
self::ChoixMultiple => 'value_option',
self::SelectionNumerique => 'value_integer',
```

`aDesOptions()` — modifier :
```php
return $this === self::ChoixUnique || $this === self::ChoixMultiple;
```

`estReponse()` reste inchangé — le pattern `$this !== self::Information` couvre déjà les nouveaux types.

- [ ] **Step 4 : Vérifier que les tests passent**

Run: `./vendor/bin/sail exec -T laravel.test php artisan test --filter="TypeQuestionTest" 2>&1 | tail -20`
Expected: PASS — tous les tests verts.

- [ ] **Step 5 : Commit**

```bash
git add app/Enums/TypeQuestion.php tests/Unit/Enums/TypeQuestionTest.php
git commit -m "feat(questionnaires): 5 nouveaux types dans l'enum TypeQuestion

Date, ChoixMultiple, Nombre, Email, SelectionNumerique.

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 2 : Éditeur (ModeleEditor + blade)

**Files :**
- Modify: `app/Livewire/Questionnaire/ModeleEditor.php`
- Modify: `resources/views/livewire/questionnaire/modele-editor.blade.php`
- Modify: `tests/Livewire/Questionnaire/ModeleEditorTest.php`

- [ ] **Step 1 : Écrire les tests éditeur pour les nouveaux types**

Ajouter dans `tests/Livewire/Questionnaire/ModeleEditorTest.php` :

```php
it('ajouterQuestion crée une question ChoixMultiple avec options', function (): void {
    $template = QuestionnaireTemplate::factory()->create();

    Livewire::test(ModeleEditor::class, ['template' => $template])
        ->set('libelle', 'Langues parlées')
        ->set('type', 'choix_multiple')
        ->set('optionsBrut', "Français\nAnglais\nEspagnol")
        ->call('ajouterQuestion');

    $q = $template->questions()->first();
    expect($q->type)->toBe(TypeQuestion::ChoixMultiple)
        ->and($q->config['options'])->toHaveCount(3)
        ->and($q->config['rendu'])->toBe('auto');
});

it('ajouterQuestion crée une question Nombre avec min/max', function (): void {
    $template = QuestionnaireTemplate::factory()->create();

    Livewire::test(ModeleEditor::class, ['template' => $template])
        ->set('libelle', 'Distance (km)')
        ->set('type', 'nombre')
        ->set('nombreMin', '0')
        ->set('nombreMax', '500')
        ->call('ajouterQuestion');

    $q = $template->questions()->first();
    expect($q->type)->toBe(TypeQuestion::Nombre)
        ->and($q->config['min'])->toBe(0.0)
        ->and($q->config['max'])->toBe(500.0);
});

it('ajouterQuestion crée une question SelectionNumerique avec min/max requis', function (): void {
    $template = QuestionnaireTemplate::factory()->create();

    Livewire::test(ModeleEditor::class, ['template' => $template])
        ->set('libelle', 'Votre âge')
        ->set('type', 'selection_numerique')
        ->set('selectionMin', '18')
        ->set('selectionMax', '99')
        ->call('ajouterQuestion');

    $q = $template->questions()->first();
    expect($q->type)->toBe(TypeQuestion::SelectionNumerique)
        ->and($q->config['min'])->toBe(18)
        ->and($q->config['max'])->toBe(99);
});

it('ajouterQuestion crée une question Date sans config', function (): void {
    $template = QuestionnaireTemplate::factory()->create();

    Livewire::test(ModeleEditor::class, ['template' => $template])
        ->set('libelle', 'Date de naissance')
        ->set('type', 'date')
        ->call('ajouterQuestion');

    $q = $template->questions()->first();
    expect($q->type)->toBe(TypeQuestion::Date)
        ->and($q->config)->toBeNull();
});

it('ajouterQuestion crée une question Email sans config', function (): void {
    $template = QuestionnaireTemplate::factory()->create();

    Livewire::test(ModeleEditor::class, ['template' => $template])
        ->set('libelle', 'Adresse email')
        ->set('type', 'email')
        ->call('ajouterQuestion');

    $q = $template->questions()->first();
    expect($q->type)->toBe(TypeQuestion::Email)
        ->and($q->config)->toBeNull();
});
```

- [ ] **Step 2 : Vérifier que les tests échouent**

Run: `./vendor/bin/sail exec -T laravel.test php artisan test --filter="ModeleEditorTest" 2>&1 | tail -20`
Expected: FAIL — propriétés `nombreMin`/`nombreMax`/`selectionMin`/`selectionMax` inexistantes.

- [ ] **Step 3 : Ajouter les propriétés et le buildConfig dans ModeleEditor.php**

Ajouter les propriétés au composant :

```php
/** Bornes nombre (optionnelles). */
public string $nombreMin = '';
public string $nombreMax = '';

/** Bornes sélection numérique (requises). */
public string $selectionMin = '';
public string $selectionMax = '';
```

Dans `editerQuestion()`, après le chargement des props existantes, ajouter :

```php
$this->nombreMin = isset($config['min']) && $question->type === TypeQuestion::Nombre ? (string) $config['min'] : '';
$this->nombreMax = isset($config['max']) && $question->type === TypeQuestion::Nombre ? (string) $config['max'] : '';
$this->selectionMin = isset($config['min']) && $question->type === TypeQuestion::SelectionNumerique ? (string) $config['min'] : '';
$this->selectionMax = isset($config['max']) && $question->type === TypeQuestion::SelectionNumerique ? (string) $config['max'] : '';
```

Dans `buildConfig()`, ajouter AVANT le bloc `if (! $type->aDesOptions())` :

```php
if ($type === TypeQuestion::Nombre) {
    $config = [];
    if ($this->nombreMin !== '') {
        $config['min'] = (float) $this->nombreMin;
    }
    if ($this->nombreMax !== '') {
        $config['max'] = (float) $this->nombreMax;
    }
    return $config !== [] ? $config : null;
}

if ($type === TypeQuestion::SelectionNumerique) {
    return [
        'min' => (int) $this->selectionMin,
        'max' => (int) $this->selectionMax,
    ];
}
```

Le bloc `if (! $type->aDesOptions())` existant retourne déjà `null` pour Date et Email (pas d'options). Pour ChoixMultiple, `aDesOptions()` retourne `true`, donc il tombe dans le bloc `options` existant — aucune modification.

Dans `resetFormulaire()`, ajouter `'nombreMin', 'nombreMax', 'selectionMin', 'selectionMax'` au `reset(...)`.

- [ ] **Step 4 : Ajouter les champs config dans le blade éditeur**

Dans `resources/views/livewire/questionnaire/modele-editor.blade.php`, après le bloc `@if ($type === 'choix_unique')`, ajouter :

```blade
@if ($type === 'choix_multiple')
    <div class="col-md-12">
        <label class="form-label small text-muted">Options (une par ligne)</label>
        <textarea class="form-control" rows="3" wire:model="optionsBrut"></textarea>
    </div>
@endif
@if ($type === 'nombre')
    <div class="col-md-6">
        <input type="number" step="any" class="form-control"
               placeholder="Min (optionnel)"
               wire:model="nombreMin">
    </div>
    <div class="col-md-6">
        <input type="number" step="any" class="form-control"
               placeholder="Max (optionnel)"
               wire:model="nombreMax">
    </div>
@endif
@if ($type === 'selection_numerique')
    <div class="col-md-6">
        <input type="number" class="form-control"
               placeholder="Min (requis)"
               wire:model="selectionMin">
    </div>
    <div class="col-md-6">
        <input type="number" class="form-control"
               placeholder="Max (requis)"
               wire:model="selectionMax">
    </div>
@endif
```

- [ ] **Step 5 : Vérifier que les tests passent**

Run: `./vendor/bin/sail exec -T laravel.test php artisan test --filter="ModeleEditorTest" 2>&1 | tail -20`
Expected: PASS.

- [ ] **Step 6 : Commit**

```bash
git add app/Livewire/Questionnaire/ModeleEditor.php resources/views/livewire/questionnaire/modele-editor.blade.php tests/Livewire/Questionnaire/ModeleEditorTest.php
git commit -m "feat(questionnaires): éditeur pour les 5 nouveaux types

Config options pour ChoixMultiple (optionsBrut partagé),
min/max pour Nombre et SelectionNumerique.

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 3 : Formulaire web (champ.blade.php)

**Files :**
- Modify: `resources/views/questionnaire/repondant/partials/champ.blade.php`

Pas de test automatisé pour les blades — vérification visuelle par l'utilisateur via l'aperçu questionnaire.

- [ ] **Step 1 : Ajouter les 5 cases dans champ.blade.php**

Ajouter après le `@case('choix_unique')` ... `@break`, avant `@endswitch` :

```blade
    @case('date')
        <input type="date"
               class="form-control"
               name="{{ $fieldName }}"
               value="{{ $oldValue }}">
        @break

    @case('choix_multiple')
        @php
            $options = $question->options();
            $selected = is_array($oldValue) ? $oldValue : (is_string($oldValue) ? json_decode($oldValue, true) ?? [] : []);
        @endphp
        <div class="d-flex flex-column gap-2">
            <div class="small text-muted fst-italic mb-1">Cochez une ou plusieurs réponses</div>
            @foreach ($options as $opt)
                <div class="form-check">
                    <input class="form-check-input" type="checkbox"
                           name="{{ $fieldName }}[]"
                           id="{{ $fieldName }}_{{ $loop->index }}"
                           value="{{ $opt['valeur'] }}"
                           {{ in_array($opt['valeur'], $selected, true) ? 'checked' : '' }}>
                    <label class="form-check-label" for="{{ $fieldName }}_{{ $loop->index }}">
                        {{ $opt['libelle'] }}
                    </label>
                </div>
            @endforeach
        </div>
        @break

    @case('nombre')
        <input type="number"
               step="any"
               class="form-control"
               name="{{ $fieldName }}"
               value="{{ $oldValue }}"
               @if (isset($question->config['min'])) min="{{ $question->config['min'] }}" @endif
               @if (isset($question->config['max'])) max="{{ $question->config['max'] }}" @endif>
        @break

    @case('email')
        @php $emailFieldId = preg_replace('/[^a-z0-9_-]/i', '_', $fieldName); @endphp
        <input type="email"
               class="form-control mb-2"
               name="{{ $fieldName }}"
               id="email_{{ $emailFieldId }}"
               value="{{ $oldValue }}"
               placeholder="Adresse email">
        <input type="email"
               class="form-control"
               id="email_confirm_{{ $emailFieldId }}"
               placeholder="Confirmez votre adresse email">
        <div class="invalid-feedback" id="email_error_{{ $emailFieldId }}" style="display:none;">
            Les adresses ne correspondent pas.
        </div>
        <script>
        (function () {
            var e1 = document.getElementById('email_{{ $emailFieldId }}');
            var e2 = document.getElementById('email_confirm_{{ $emailFieldId }}');
            var err = document.getElementById('email_error_{{ $emailFieldId }}');
            if (!e1 || !e2) return;
            function check() {
                var mismatch = e2.value !== '' && e1.value !== e2.value;
                e2.classList.toggle('is-invalid', mismatch);
                err.style.display = mismatch ? 'block' : 'none';
            }
            e2.addEventListener('input', check);
            e1.addEventListener('input', check);
            // Block form submit if mismatch
            var form = e1.closest('form');
            if (form) {
                form.addEventListener('submit', function (ev) {
                    if (e2.value !== '' && e1.value !== e2.value) {
                        ev.preventDefault();
                        e2.classList.add('is-invalid');
                        err.style.display = 'block';
                        e2.focus();
                    }
                });
            }
        })();
        </script>
        @break

    @case('selection_numerique')
        @php
            $selMin = (int) ($question->config['min'] ?? 0);
            $selMax = (int) ($question->config['max'] ?? 100);
        @endphp
        <select class="form-select" name="{{ $fieldName }}">
            <option value="">— Choisir —</option>
            @for ($i = $selMin; $i <= $selMax; $i++)
                <option value="{{ $i }}" {{ (string) $oldValue === (string) $i ? 'selected' : '' }}>{{ $i }}</option>
            @endfor
        </select>
        @break
```

- [ ] **Step 2 : Commit**

```bash
git add resources/views/questionnaire/repondant/partials/champ.blade.php
git commit -m "feat(questionnaires): formulaire web pour les 5 nouveaux types

Date (input date), ChoixMultiple (checkboxes), Nombre (input number step=any),
Email (double saisie + validation JS), SelectionNumerique (select min→max).

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 4 : PDF papier (champ-papier.blade.php)

**Files :**
- Modify: `resources/views/pdf/partials/champ-papier.blade.php`

- [ ] **Step 1 : Ajouter les 5 cases dans champ-papier.blade.php**

Ajouter avant `@endswitch` (après le `@case('choix_unique')` ... `@break`) :

```blade
    @case('date')
        <div style="margin-top:8px;">
            <div style="font-size:8px; color:#888; margin-bottom:4px; font-style:italic;">Saisissez la date : JJ / MM / AAAA</div>
            <table style="border-collapse:collapse;">
                <tr>
                    @for ($i = 0; $i < 2; $i++)
                        <td style="border:1px solid #333; width:20px; height:24px; text-align:center;"></td>
                    @endfor
                    <td style="padding:0 4px; font-size:14px; font-weight:bold;">/</td>
                    @for ($i = 0; $i < 2; $i++)
                        <td style="border:1px solid #333; width:20px; height:24px; text-align:center;"></td>
                    @endfor
                    <td style="padding:0 4px; font-size:14px; font-weight:bold;">/</td>
                    @for ($i = 0; $i < 4; $i++)
                        <td style="border:1px solid #333; width:20px; height:24px; text-align:center;"></td>
                    @endfor
                </tr>
                <tr>
                    <td colspan="2" style="font-size:7px; color:#999; text-align:center;">J&nbsp;&nbsp;J</td>
                    <td></td>
                    <td colspan="2" style="font-size:7px; color:#999; text-align:center;">M&nbsp;&nbsp;M</td>
                    <td></td>
                    <td colspan="4" style="font-size:7px; color:#999; text-align:center;">A&nbsp;&nbsp;A&nbsp;&nbsp;A&nbsp;&nbsp;A</td>
                </tr>
            </table>
        </div>
        @break

    @case('choix_multiple')
        @php $options = $question->options(); @endphp
        <div style="margin-top:6px;">
            <div style="font-size:8px; color:#888; margin-bottom:3px; font-style:italic;">Cochez une ou plusieurs réponses</div>
            @foreach($options as $opt)
                <div style="margin-bottom:4px;">
                    <span style="font-size:16px; line-height:1; vertical-align:middle;">&#9744;</span>
                    <span style="font-size:10px; color:#333; margin-left:6px; vertical-align:middle;">{{ $opt['libelle'] }}</span>
                </div>
            @endforeach
        </div>
        @break

    @case('nombre')
        @php
            $instr = 'Entrez un nombre';
            if (isset($question->config['min'], $question->config['max'])) {
                $instr .= ' entre ' . $question->config['min'] . ' et ' . $question->config['max'];
            } elseif (isset($question->config['min'])) {
                $instr .= ' (min : ' . $question->config['min'] . ')';
            } elseif (isset($question->config['max'])) {
                $instr .= ' (max : ' . $question->config['max'] . ')';
            }
        @endphp
        <div style="margin-top:6px;">
            <div style="font-size:8px; color:#888; margin-bottom:3px; font-style:italic;">{{ $instr }}</div>
            <div style="border-bottom:1px solid #333; height:1.6em;"></div>
        </div>
        @break

    @case('email')
        <div style="margin-top:6px;">
            <div style="font-size:8px; color:#888; margin-bottom:3px; font-style:italic;">Adresse email</div>
            <div style="border-bottom:1px solid #333; height:1.6em;"></div>
        </div>
        @break

    @case('selection_numerique')
        @php
            $sMin = (int) ($question->config['min'] ?? 0);
            $sMax = (int) ($question->config['max'] ?? 100);
        @endphp
        <div style="margin-top:6px;">
            <div style="font-size:8px; color:#888; margin-bottom:3px; font-style:italic;">Entrez un nombre entre {{ $sMin }} et {{ $sMax }}</div>
            <div style="border-bottom:1px solid #333; height:1.6em;"></div>
        </div>
        @break
```

- [ ] **Step 2 : Commit**

```bash
git add resources/views/pdf/partials/champ-papier.blade.php
git commit -m "feat(questionnaires): rendu papier pour les 5 nouveaux types

Date (8 cases JJ/MM/AAAA), ChoixMultiple (☐ cases), Nombre (ligne + bornes),
Email (ligne), SelectionNumerique (ligne + plage).

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 5 : Normalisation et validation (QuestionnaireReponseService)

**Files :**
- Modify: `app/Services/Questionnaire/QuestionnaireReponseService.php`
- Create: `tests/Feature/Questionnaire/NouveauxTypesNormalisationTest.php`

- [ ] **Step 1 : Écrire les tests de normalisation**

Créer `tests/Feature/Questionnaire/NouveauxTypesNormalisationTest.php` :

```php
<?php

declare(strict_types=1);

use App\Enums\TypeQuestion;
use App\Models\Operation;
use App\Models\QuestionnaireBearerToken;
use App\Models\QuestionnaireCampaign;
use App\Models\QuestionnaireCampaignQuestion;
use App\Services\Questionnaire\QuestionnaireReponseService;
use App\Tenant\TenantContext;

function campagneAvecQuestion(string $slug, ?array $config = null): array
{
    $op = Operation::factory()->create();
    $campagne = QuestionnaireCampaign::factory()->for($op, 'operation')->create(['statut' => 'ouverte', 'anonymise' => true]);
    $question = QuestionnaireCampaignQuestion::factory()->for($campagne, 'campaign')->create([
        'libelle' => 'Test', 'type' => $slug, 'ordre' => 1, 'config' => $config,
    ]);
    $bearer = QuestionnaireBearerToken::create([
        'association_id' => TenantContext::currentId(),
        'campaign_id' => (int) $campagne->id,
        'token_hash' => hash('sha256', 'bearer-'.uniqid()),
    ]);

    return [$campagne, $question, $bearer];
}

it('normalise une date ISO dans value_text', function (): void {
    [$campagne, $question, $bearer] = campagneAvecQuestion('date');

    $sub = app(QuestionnaireReponseService::class)->creerDepuisOcrAnonyme(
        bearer: $bearer,
        valeursParQuestionId: [(string) $question->id => '2026-03-15'],
    );

    $answer = $sub->answers()->first();
    expect($answer->value_text)->toBe('2026-03-15');
});

it('normalise un choix multiple en JSON dans value_option', function (): void {
    [$campagne, $question, $bearer] = campagneAvecQuestion('choix_multiple', [
        'rendu' => 'auto',
        'options' => [
            ['valeur' => 'opt_a', 'libelle' => 'Français', 'ordre' => 1],
            ['valeur' => 'opt_b', 'libelle' => 'Anglais', 'ordre' => 2],
        ],
    ]);

    $sub = app(QuestionnaireReponseService::class)->creerDepuisOcrAnonyme(
        bearer: $bearer,
        valeursParQuestionId: [(string) $question->id => ['opt_a', 'opt_b']],
    );

    $answer = $sub->answers()->first();
    expect(json_decode($answer->value_option, true))->toBe(['opt_a', 'opt_b'])
        ->and($answer->value_meta['libelles'])->toBe(['Français', 'Anglais']);
});

it('normalise un nombre décimal dans value_text', function (): void {
    [$campagne, $question, $bearer] = campagneAvecQuestion('nombre');

    $sub = app(QuestionnaireReponseService::class)->creerDepuisOcrAnonyme(
        bearer: $bearer,
        valeursParQuestionId: [(string) $question->id => '37.5'],
    );

    $answer = $sub->answers()->first();
    expect($answer->value_text)->toBe('37.5');
});

it('normalise un email dans value_text', function (): void {
    [$campagne, $question, $bearer] = campagneAvecQuestion('email');

    $sub = app(QuestionnaireReponseService::class)->creerDepuisOcrAnonyme(
        bearer: $bearer,
        valeursParQuestionId: [(string) $question->id => 'test@example.com'],
    );

    $answer = $sub->answers()->first();
    expect($answer->value_text)->toBe('test@example.com');
});

it('normalise une sélection numérique dans value_integer', function (): void {
    [$campagne, $question, $bearer] = campagneAvecQuestion('selection_numerique', ['min' => 18, 'max' => 99]);

    $sub = app(QuestionnaireReponseService::class)->creerDepuisOcrAnonyme(
        bearer: $bearer,
        valeursParQuestionId: [(string) $question->id => '42'],
    );

    $answer = $sub->answers()->first();
    expect($answer->value_integer)->toBe(42);
});
```

- [ ] **Step 2 : Vérifier que les tests échouent**

Run: `./vendor/bin/sail exec -T laravel.test php artisan test --filter="NouveauxTypesNormalisation" 2>&1 | tail -20`
Expected: FAIL — les match arms manquent dans `normaliser()`.

- [ ] **Step 3 : Ajouter les match arms dans normaliser()**

Dans `QuestionnaireReponseService::normaliser()`, modifier le match statement :

```php
$payload = match ($question->type) {
    TypeQuestion::TexteCourt, TypeQuestion::TexteLong => ($v === null || $v === '') ? $base : [...$base, 'value_text' => (string) $v],
    TypeQuestion::Date, TypeQuestion::Email => ($v === null || $v === '') ? $base : [...$base, 'value_text' => (string) $v],
    TypeQuestion::Nombre => ($v === null || $v === '') ? $base : [...$base, 'value_text' => (string) $v],
    TypeQuestion::Satisfaction, TypeQuestion::SatisfactionTexteLong, TypeQuestion::Ressenti => ($v === null || $v === '') ? $base : [...$base, 'value_integer' => (int) $v],
    TypeQuestion::SelectionNumerique => ($v === null || $v === '') ? $base : [...$base, 'value_integer' => (int) $v],
    TypeQuestion::CaseACocher => ($v === null || $v === '') ? $base : [...$base, 'value_boolean' => (bool) $v],
    TypeQuestion::ChoixUnique => ($v === null || $v === '') ? $base : [
        ...$base,
        'value_option' => (string) $v,
        'value_meta' => ['libelle' => $question->libelleOption((string) $v)],
    ],
    TypeQuestion::ChoixMultiple => ($v === null || $v === '' || $v === []) ? $base : [
        ...$base,
        'value_option' => json_encode(is_array($v) ? $v : [$v]),
        'value_meta' => ['libelles' => collect(is_array($v) ? $v : [$v])
            ->map(fn (string $val): ?string => $question->libelleOption($val))
            ->filter()
            ->values()
            ->all()],
    ],
};
```

Note : `Date` et `Email` partagent le même arm que `TexteCourt`/`TexteLong` (tous dans `value_text`), mais sont déclarés séparément pour la clarté. On peut les fusionner dans le même arm si souhaité.

Simplification possible — fusionner les arms identiques :

```php
TypeQuestion::TexteCourt, TypeQuestion::TexteLong, TypeQuestion::Date, TypeQuestion::Email, TypeQuestion::Nombre
    => ($v === null || $v === '') ? $base : [...$base, 'value_text' => (string) $v],
TypeQuestion::Satisfaction, TypeQuestion::SatisfactionTexteLong, TypeQuestion::Ressenti, TypeQuestion::SelectionNumerique
    => ($v === null || $v === '') ? $base : [...$base, 'value_integer' => (int) $v],
```

- [ ] **Step 4 : Vérifier que les tests passent**

Run: `./vendor/bin/sail exec -T laravel.test php artisan test --filter="NouveauxTypesNormalisation" 2>&1 | tail -20`
Expected: PASS.

- [ ] **Step 5 : Commit**

```bash
git add app/Services/Questionnaire/QuestionnaireReponseService.php tests/Feature/Questionnaire/NouveauxTypesNormalisationTest.php
git commit -m "feat(questionnaires): normalisation des 5 nouveaux types

Date/Email/Nombre → value_text, SelectionNumerique → value_integer,
ChoixMultiple → value_option JSON + value_meta libellés.

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 6 : Résultats (agrégation + affichage consolidé + individuel)

**Files :**
- Modify: `app/Services/Questionnaire/QuestionnaireResultatService.php`
- Modify: `resources/views/questionnaire/resultats/_resultats.blade.php`
- Modify: `resources/views/questionnaire/resultats/_reponses-individuelles.blade.php`

- [ ] **Step 1 : Ajouter les match arms dans agreger()**

Dans `QuestionnaireResultatService::agreger()`, ajouter les nouveaux match arms :

```php
TypeQuestion::Date, TypeQuestion::Email => [
    'verbatims' => $answers->pluck('value_text')->filter()->values()->all(),
    'n' => $answers->count(),
],
TypeQuestion::Nombre => [
    'moyenne' => $answers->isNotEmpty()
        ? round((float) $answers->avg(fn ($a) => (float) $a->value_text), 2)
        : null,
    'min' => $answers->isNotEmpty()
        ? (float) $answers->min(fn ($a) => (float) $a->value_text)
        : null,
    'max' => $answers->isNotEmpty()
        ? (float) $answers->max(fn ($a) => (float) $a->value_text)
        : null,
    'n' => $answers->count(),
],
TypeQuestion::ChoixMultiple => [
    'repartition' => collect($question->options())->map(function (array $opt) use ($answers): array {
        $count = $answers->filter(function ($a) use ($opt): bool {
            $selected = json_decode($a->value_option ?? '[]', true);
            return is_array($selected) && in_array($opt['valeur'], $selected, true);
        })->count();
        return ['libelle' => $opt['libelle'], 'count' => $count];
    })->all(),
    'n' => $answers->count(),
],
TypeQuestion::SelectionNumerique => [
    'moyenne' => $answers->isNotEmpty()
        ? round((float) $answers->avg('value_integer'), 1)
        : null,
    'distribution' => $answers->countBy('value_integer')->all(),
    'n' => $answers->count(),
],
```

Note : `Date` et `Email` partagent le même pattern `verbatims` que `TexteCourt`/`TexteLong`. On peut les fusionner :

```php
TypeQuestion::TexteCourt, TypeQuestion::TexteLong, TypeQuestion::Date, TypeQuestion::Email => [
    'verbatims' => $answers->pluck('value_text')->filter()->values()->all(),
    'n' => $answers->count(),
],
```

Et `SelectionNumerique` peut fusionner avec `Satisfaction`/`Ressenti` (même pattern moyenne + distribution).

- [ ] **Step 2 : Ajouter les blocs dans _resultats.blade.php**

Ajouter les blocs pour les nouveaux types dans la boucle `@forelse`. Après le bloc `TexteCourt`/`TexteLong`, avant `@endif` :

```blade
            @elseif ($q['type'] === \App\Enums\TypeQuestion::Nombre)
                @if ($q['moyenne'] !== null)
                    <div class="mb-2">
                        <span class="fs-4 fw-bold">{{ number_format($q['moyenne'], 2, ',', '') }}</span>
                        <span class="text-muted small ms-2">({{ $q['n'] }} réponse{{ $q['n'] > 1 ? 's' : '' }})</span>
                    </div>
                    <div class="d-flex gap-3">
                        <span class="badge bg-light text-dark border">Min : {{ $q['min'] }}</span>
                        <span class="badge bg-light text-dark border">Max : {{ $q['max'] }}</span>
                    </div>
                @else
                    <span class="text-muted">Aucune réponse.</span>
                @endif

            @elseif ($q['type'] === \App\Enums\TypeQuestion::ChoixMultiple)
                @forelse ($q['repartition'] as $item)
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <span>{{ $item['libelle'] }}</span>
                        <span class="badge bg-primary rounded-pill">{{ $item['count'] }}</span>
                    </div>
                @empty
                    <span class="text-muted">Aucune réponse.</span>
                @endforelse
                <div class="text-muted small mt-1">{{ $q['n'] }} réponse{{ $q['n'] > 1 ? 's' : '' }}</div>
```

Note : `Date`, `Email` et `SelectionNumerique` sont déjà couverts par les blocs existants si on fusionne les match arms : Date/Email → même pattern que TexteCourt (verbatims), SelectionNumerique → même pattern que Satisfaction (moyenne + distribution). Mais on doit ajouter les `||` dans les conditions blade existantes :

Dans le bloc Satisfaction/Ressenti, ajouter `SelectionNumerique` :
```blade
@if ($q['type'] === \App\Enums\TypeQuestion::Satisfaction || $q['type'] === \App\Enums\TypeQuestion::Ressenti || $q['type'] === \App\Enums\TypeQuestion::SatisfactionTexteLong || $q['type'] === \App\Enums\TypeQuestion::SelectionNumerique)
```

Dans le bloc TexteCourt/TexteLong, ajouter Date et Email :
```blade
@elseif ($q['type'] === \App\Enums\TypeQuestion::TexteCourt || $q['type'] === \App\Enums\TypeQuestion::TexteLong || $q['type'] === \App\Enums\TypeQuestion::Date || $q['type'] === \App\Enums\TypeQuestion::Email)
```

Pour SelectionNumerique dans le bloc Satisfaction, le diviseur « / 5 » ou « / 100 » doit aussi gérer la plage min-max. Ajouter le cas :
```blade
<span class="text-muted small ms-1">
    / {{ $q['type'] === \App\Enums\TypeQuestion::Ressenti ? '100' : ($q['type'] === \App\Enums\TypeQuestion::SelectionNumerique ? '' : '5') }}
</span>
```
Pour SelectionNumerique, ne pas afficher de diviseur (la moyenne parle d'elle-même). Alternativement, afficher la plage min-max dans le badge.

- [ ] **Step 3 : Ajouter les cases dans _reponses-individuelles.blade.php**

Dans le `@switch($q->type)` de `_reponses-individuelles.blade.php`, ajouter après le dernier `@break` :

```blade
@case(TypeQuestion::Date)
    @if ($answer->value_text)
        {{ \Carbon\Carbon::parse($answer->value_text)->format('d/m/Y') }}
    @endif
    @break

@case(TypeQuestion::ChoixMultiple)
    @php
        $selectedValues = json_decode($answer->value_option ?? '[]', true) ?? [];
        $libelles = $answer->value_meta['libelles'] ?? [];
    @endphp
    @foreach ($libelles as $lib)
        <span class="badge bg-primary me-1">{{ $lib }}</span>
    @endforeach
    @if (empty($libelles) && !empty($selectedValues))
        @foreach ($selectedValues as $val)
            <span class="badge bg-secondary me-1">{{ $q->libelleOption($val) ?? $val }}</span>
        @endforeach
    @endif
    @break

@case(TypeQuestion::Nombre)
    {{ $answer->value_text ?? '—' }}
    @break

@case(TypeQuestion::Email)
    {{ $answer->value_text ?? '—' }}
    @break

@case(TypeQuestion::SelectionNumerique)
    {{ $answer->value_integer }}
    @break
```

- [ ] **Step 4 : Commit**

```bash
git add app/Services/Questionnaire/QuestionnaireResultatService.php resources/views/questionnaire/resultats/_resultats.blade.php resources/views/questionnaire/resultats/_reponses-individuelles.blade.php
git commit -m "feat(questionnaires): résultats pour les 5 nouveaux types

Agrégation : verbatims (Date/Email), répartition multi (ChoixMultiple),
moyenne+min+max (Nombre), moyenne+distribution (SelectionNumerique).

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 7 : Export Excel (QuestionnaireExcelExporter)

**Files :**
- Modify: `app/Services/Questionnaire/QuestionnaireExcelExporter.php`

- [ ] **Step 1 : Ajouter les match arms dans valeurAffichee()**

Dans `QuestionnaireExcelExporter::valeurAffichee()` :

```php
return match ($type) {
    TypeQuestion::TexteCourt, TypeQuestion::TexteLong, TypeQuestion::Date, TypeQuestion::Email, TypeQuestion::Nombre
        => $answer->value_text ?? '',
    TypeQuestion::Satisfaction, TypeQuestion::SatisfactionTexteLong, TypeQuestion::Ressenti, TypeQuestion::SelectionNumerique
        => $answer->value_integer ?? '',
    TypeQuestion::CaseACocher => $answer->value_boolean ? 'Oui' : 'Non',
    TypeQuestion::ChoixUnique => $question->libelleOption((string) $answer->value_option) ?? ($answer->value_option ?? ''),
    TypeQuestion::ChoixMultiple => implode(', ', $answer->value_meta['libelles'] ?? json_decode($answer->value_option ?? '[]', true) ?? []),
};
```

- [ ] **Step 2 : Commit**

```bash
git add app/Services/Questionnaire/QuestionnaireExcelExporter.php
git commit -m "feat(questionnaires): export Excel pour les 5 nouveaux types

ChoixMultiple → libellés joints par virgule.

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 8 : OCR (QuestionnaireOcrService)

**Files :**
- Modify: `app/Services/Questionnaire/QuestionnaireOcrService.php`

- [ ] **Step 1 : Ajouter les règles dans buildPrompt()**

Dans `QuestionnaireOcrService::buildPrompt()`, après la ligne `"- texte_court / texte_long : ..."`, ajouter :

```php
"- date : value = date au format AAAA-MM-JJ (année-mois-jour)\n".
"- choix_multiple : value = tableau JSON des VALEURS TECHNIQUES cochées (ex: [\"opt_abc\",\"opt_def\"])\n".
"- nombre : value = nombre (entier ou décimal, ex: 42 ou 37.5)\n".
"- email : value = adresse email\n".
"- selection_numerique : value = entier\n".
```

Pour `choix_multiple`, le type a `aDesOptions()` = true, donc la liste des options est déjà envoyée par la ligne existante `($q->aDesOptions() ? ' [options: ...' : '')`.

- [ ] **Step 2 : Ajouter les valeurs dans demoStub()**

Dans `QuestionnaireOcrService::demoStub()`, modifier le match :

```php
(string) $q->id => ['value' => match ($q->type->value) {
    'satisfaction', 'satisfaction_texte_long' => 4,
    'ressenti' => 65,
    'case_a_cocher' => true,
    'date' => '2026-01-15',
    'choix_multiple' => [($q->config['options'][0]['valeur'] ?? 'opt_1')],
    'nombre' => 42,
    'email' => 'exemple@email.fr',
    'selection_numerique' => intdiv(($q->config['min'] ?? 0) + ($q->config['max'] ?? 100), 2),
    default => 'exemple',
}, 'confidence' => 0.75],
```

- [ ] **Step 3 : Commit**

```bash
git add app/Services/Questionnaire/QuestionnaireOcrService.php
git commit -m "feat(questionnaires): OCR pour les 5 nouveaux types

Règles d'extraction dans buildPrompt() + valeurs démo.

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 9 : Pint + suite verte + commit final

**Files :**
- Tous les fichiers modifiés

- [ ] **Step 1 : Lancer pint**

```bash
./vendor/bin/sail exec -T laravel.test ./vendor/bin/pint --dirty
```

Corriger si nécessaire.

- [ ] **Step 2 : Lancer la suite complète questionnaires**

```bash
./vendor/bin/sail exec -T laravel.test php artisan test --filter="Questionnaire" 2>&1 | tail -10
```

Expected: 0 failed.

- [ ] **Step 3 : Lancer les tests unitaires TypeQuestion**

```bash
./vendor/bin/sail exec -T laravel.test php artisan test --filter="TypeQuestionTest" 2>&1 | tail -10
```

Expected: PASS.

- [ ] **Step 4 : Commit pint si nécessaire**

```bash
git add -u && git commit -m "style: pint

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

- [ ] **Step 5 : Checkout config/version.php**

Piège récurrent — le fichier `config/version.php` s'auto-stampe à chaque run artisan :

```bash
git checkout config/version.php
```
