# Questionnaires — Composition, aperçu & envoi (+ commentaire satisfaction) — Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Enrichir le V1 numérique des questionnaires : messages (intro/remerciement) composés en TinyMCE avec variables d'opération, mode aperçu sans enregistrement, envoi des invitations par la messagerie, et commentaire optionnel sur les questions de satisfaction.

**Architecture:** Un socle `QuestionnaireVariableResolver` produit la map `{variable}→valeur` (réel / exemple / +lien), réutilisé par le rendu répondant, l'aperçu et l'envoi. Les messages riches réutilisent `EmailTemplate::sanitizeCorps()` (assainissement + protection des `{var}` en href) et le pattern TinyMCE+Alpine existant. L'aperçu rejoue le parcours réel en GET sans persistance, via un partial de champ partagé. L'envoi réutilise `Mailable` + `EmailLog`.

**Tech Stack:** Laravel 11, Livewire 4, Bootstrap 5, TinyMCE 6 self-hosted, Pest, openspout, dompdf. Branche : `feat/questionnaires` (lots 1–5 déjà livrés).

**Référence spec :** [docs/specs/2026-06-23-questionnaires-composition-apercu-envoi.md](../docs/specs/2026-06-23-questionnaires-composition-apercu-envoi.md)

**Conventions :** `declare(strict_types=1)` + `final` + type hints ; PSR-12 (`./vendor/bin/pint`) ; locale fr ; cast `(int)` des deux côtés des `===` PK/FK. Tests : `vendor/bin/pest <chemin>` (SQLite `:memory:`, tenant booté par le bootstrap Pest global). **PHP 8.5 local émet des `Deprecated:` — ce ne sont PAS des échecs** ; seul `FAILED`/`Tests: X failed` l'est.

**Refinement vs spec §3.1 :** l'édition TinyMCE de **intro/remerciement** se fait dans la page **`ModeleEditor`** (plein écran), pas dans la modale `ModeleList` — pour mirrorer fidèlement le pattern TinyMCE+Alpine plein écran d'`OperationCommunication` (TinyMCE-dans-modale est le piège que la spec §9 demande d'éviter). Le `titre_affiche` reste dans la modale `ModeleList` (input simple, variables autorisées).

---

## File structure

**Nouveau :**
- `app/Services/Questionnaire/QuestionnaireVariableResolver.php` (Phase 0)
- `resources/views/questionnaire/repondant/partials/champ.blade.php` (Phase B — partial partagé)
- `app/Http/Controllers/QuestionnaireApercuController.php` (Phase B)
- `resources/views/questionnaire/apercu/*.blade.php` (Phase B)
- `app/Mail/QuestionnaireInvitationMail.php` + `resources/views/emails/questionnaire-invitation.blade.php` (Phase C)
- `app/Services/Questionnaire/QuestionnaireEnvoiService.php` (Phase C)
- `app/Livewire/Questionnaire/EnvoiCompose.php` + `resources/views/livewire/questionnaire/envoi-compose.blade.php` (Phase C)

**Modifié :**
- `app/Livewire/Questionnaire/ModeleEditor.php` + sa vue (Phase A messages, Phase D config commentaire)
- `app/Livewire/Questionnaire/ModeleList.php` + sa vue (Phase A : retirer intro/remerciement de la modale)
- `resources/views/questionnaire/repondant/{intro,merci,question}.blade.php` (Phase A rendu résolu, Phase D commentaire)
- `app/Services/Questionnaire/QuestionnaireReponseService.php` (Phase D : commentaire + obligatoire révisé)
- `app/Http/Controllers/QuestionnaireRepondantController.php` (Phase D : lire `q_{id}_commentaire`)
- `app/Services/Questionnaire/QuestionnaireResultatService.php` (Phase D : verbatims satisfaction)
- `app/Services/Questionnaire/QuestionnaireExcelExporter.php` (Phase D : 2e colonne)
- `app/Livewire/Questionnaire/OperationQuestionnaires.php` + sa vue (Phase B aperçu, Phase C envoi/relance)
- `routes/web.php` (routes aperçu)

---

## PHASE 0 — `QuestionnaireVariableResolver`

### Task 0.1 : le resolver

**Files:**
- Create: `app/Services/Questionnaire/QuestionnaireVariableResolver.php`
- Test: `tests/Feature/Questionnaire/QuestionnaireVariableResolverTest.php`

- [ ] **Step 1 : Test**

```php
<?php

declare(strict_types=1);

use App\Models\Operation;
use App\Models\Participant;
use App\Models\QuestionnaireCampaign;
use App\Models\QuestionnaireInvitation;
use App\Models\Tiers;
use App\Services\Questionnaire\QuestionnaireVariableResolver;

it('résout les variables depuis une invitation réelle', function (): void {
    $tiers = Tiers::factory()->create(['prenom' => 'Marie', 'nom' => 'Durand']);
    $op = Operation::factory()->create(['nom' => 'Atelier sophro']);
    $participant = Participant::factory()->create(['operation_id' => $op->id, 'tiers_id' => $tiers->id]);
    $campagne = QuestionnaireCampaign::factory()->for($op, 'operation')->create();
    $invitation = QuestionnaireInvitation::factory()->for($campagne, 'campaign')->create([
        'participant_id' => $participant->id,
    ]);

    $vars = app(QuestionnaireVariableResolver::class)->pour($invitation);

    expect($vars['{prenom}'])->toBe('Marie');
    expect($vars['{operation}'])->toBe('Atelier sophro');
    expect($vars)->not->toHaveKey('{lien_questionnaire}'); // pas de lien sans avecLien
});

it('inclut le lien quand demandé', function (): void {
    $invitation = QuestionnaireInvitation::factory()->create();
    $vars = app(QuestionnaireVariableResolver::class)->pour($invitation, avecLien: true);

    expect($vars['{lien_questionnaire}'])->toBe($invitation->lienReponse());
});

it('produit des valeurs d exemple sans invitation', function (): void {
    $vars = app(QuestionnaireVariableResolver::class)->exemple();

    expect($vars['{prenom}'])->toBe('Jean');
    expect($vars['{operation}'])->toBe('Mon opération');
});

it('échappe les valeurs lors du remplacement (anti-injection)', function (): void {
    $resolver = app(QuestionnaireVariableResolver::class);
    $html = $resolver->remplacer('Bonjour {prenom}', ['{prenom}' => '<script>alert(1)</script>']);

    expect($html)->not->toContain('<script>');
    expect($html)->toContain('&lt;script&gt;');
});
```

- [ ] **Step 2 : Lancer (échoue).**

- [ ] **Step 3 : Service** — `pour()` s'appuie sur les helpers civilité de `Tiers` (voir `Tiers::civilite*`/`displayName` ; mirrorer la map de `app/Mail/MessageLibreMail.php::content()`). Format des dates en `d/m/Y`.

```php
<?php

declare(strict_types=1);

namespace App\Services\Questionnaire;

use App\Models\QuestionnaireCampaign;
use App\Models\QuestionnaireInvitation;
use App\Support\CurrentAssociation;

final class QuestionnaireVariableResolver
{
    /**
     * @return array<string, string>
     */
    public function pour(QuestionnaireInvitation $invitation, bool $avecLien = false): array
    {
        $participant = $invitation->participant;
        $tiers = $participant?->tiers;
        $operation = $invitation->campaign->operation;

        $vars = [
            '{prenom}' => (string) ($tiers?->prenom ?? ''),
            '{nom}' => (string) ($tiers?->nom ?? ''),
            '{civilite}' => (string) ($tiers?->civilite?->court() ?? ''),
            '{politesse}' => (string) ($tiers?->civilite?->long() ?? ''),
            '{operation}' => (string) ($operation?->nom ?? ''),
            '{type_operation}' => (string) ($operation?->typeOperation?->nom ?? ''),
            '{association}' => (string) (CurrentAssociation::tryGet()?->nom ?? ''),
            '{date_debut}' => $operation?->date_debut?->format('d/m/Y') ?? '',
            '{date_fin}' => $operation?->date_fin?->format('d/m/Y') ?? '',
            '{nb_seances}' => (string) ($operation?->nombre_seances ?? ''),
        ];

        if ($avecLien) {
            $vars['{lien_questionnaire}'] = $invitation->lienReponse();
        }

        return $vars;
    }

    /**
     * @return array<string, string>
     */
    public function exemple(?QuestionnaireCampaign $campagne = null): array
    {
        $operation = $campagne?->operation;

        return [
            '{prenom}' => 'Jean',
            '{nom}' => 'Dupont',
            '{civilite}' => 'M.',
            '{politesse}' => 'Monsieur',
            '{operation}' => $operation?->nom ?? 'Mon opération',
            '{type_operation}' => $operation?->typeOperation?->nom ?? 'Type d\'opération',
            '{association}' => CurrentAssociation::tryGet()?->nom ?? 'Mon association',
            '{date_debut}' => $operation?->date_debut?->format('d/m/Y') ?? now()->format('d/m/Y'),
            '{date_fin}' => $operation?->date_fin?->format('d/m/Y') ?? now()->addMonth()->format('d/m/Y'),
            '{nb_seances}' => (string) ($operation?->nombre_seances ?? '6'),
        ];
    }

    /**
     * Remplace les {variables} ; les valeurs sont échappées (anti-injection HTML).
     *
     * @param  array<string, string>  $vars
     */
    public function remplacer(string $html, array $vars): string
    {
        $echappees = array_map(fn (string $v): string => e($v), $vars);

        return strtr($html, $echappees);
    }
}
```

Note : vérifier les vrais accesseurs civilité sur `Tiers` (`civilite` enum + méthodes `court()`/`long()` ou équivalent — lire `app/Models/Tiers.php` + `app/Enums/Civilite.php`). Adapter si les noms diffèrent. Si `nombre_seances` est null, `{nb_seances}` = ''.

- [ ] **Step 4 : Lancer (passe). Commit.**

```bash
git add app/Services/Questionnaire/QuestionnaireVariableResolver.php tests/Feature/Questionnaire/QuestionnaireVariableResolverTest.php
git commit -m "feat(questionnaires): QuestionnaireVariableResolver (réel/exemple/+lien, valeurs échappées)"
```

---

## PHASE A — Composition riche intro/remerciement

### Task A.1 : déplacer intro/remerciement vers ModeleEditor (TinyMCE)

**Files:**
- Modify: `app/Livewire/Questionnaire/ModeleList.php` (retirer `intro`/`remerciement` de la modale)
- Modify: `resources/views/livewire/questionnaire/modele-list.blade.php` (retirer les 2 textarea)
- Modify: `app/Livewire/Questionnaire/ModeleEditor.php` (props `intro`/`remerciement` + `enregistrerMessages()`)
- Modify: `resources/views/livewire/questionnaire/modele-editor.blade.php` (section « Messages » avec 2 TinyMCE + insertion de variables)
- Test: `tests/Livewire/Questionnaire/ModeleEditorTest.php` (ajouter un cas)

- [ ] **Step 1 : Test** (l'éditeur enregistre intro/remerciement assainis)

```php
it('enregistre les messages intro/remerciement assainis', function (): void {
    $t = \App\Models\QuestionnaireTemplate::factory()->create();

    \Livewire\Livewire::test(\App\Livewire\Questionnaire\ModeleEditor::class, ['template' => $t])
        ->set('intro', '<p>Bonjour {prenom}</p><script>alert(1)</script>')
        ->set('remerciement', '<p>Merci !</p>')
        ->call('enregistrerMessages')
        ->assertHasNoErrors();

    $t->refresh();
    expect($t->intro)->toContain('Bonjour {prenom}');
    expect($t->intro)->not->toContain('<script>');
    expect($t->remerciement)->toContain('Merci');
});
```

- [ ] **Step 2 : Lancer (échoue).**

- [ ] **Step 3 : ModeleEditor** — Ajouter les propriétés et l'action (mount() initialise depuis le template) :

```php
public string $intro = '';
public string $remerciement = '';

// dans mount(QuestionnaireTemplate $template): void { … existant …
//   $this->intro = $template->intro ?? '';
//   $this->remerciement = $template->remerciement ?? '';
// }

public function enregistrerMessages(): void
{
    $this->template->update([
        'intro' => $this->intro === '' ? null : \App\Models\EmailTemplate::sanitizeCorps($this->intro),
        'remerciement' => $this->remerciement === '' ? null : \App\Models\EmailTemplate::sanitizeCorps($this->remerciement),
    ]);

    session()->flash('messages_ok', true);
}
```

- [ ] **Step 4 : Vue ModeleEditor** — Ajouter, au-dessus de la table des questions, une section « Messages du questionnaire » avec **2 éditeurs TinyMCE** (intro, remerciement) + un bouton « Insérer une variable » (liste : `{prenom} {nom} {operation} {type_operation} {association} {date_debut} {date_fin} {nb_seances}`). **Mirrorer le pattern TinyMCE+Alpine plein écran d'`resources/views/livewire/operation-communication.blade.php`** (bloc `wire:ignore` autour du textarea, composant Alpine `tinymce.init({ language_url:'/vendor/tinymce/langs/fr_FR.js', … })`, hook de sync TinyMCE→Livewire au `change`/avant submit, insertion via `editor.insertContent`). Bouton « Enregistrer les messages » → `wire:click="enregistrerMessages"`.

- [ ] **Step 5 : Retirer de la modale ModeleList** — Supprimer les champs `intro`/`remerciement` du composant `ModeleList` (propriétés, règles de validation, et les 2 `<textarea>` de la vue). La modale garde `titre_interne`, `titre_affiche`, et la création. (Les modèles existants conservent leurs valeurs ; elles s'éditent désormais dans l'éditeur.)

- [ ] **Step 6 : Lancer (passe).** Vérifier que `ModeleListTest` reste vert après retrait des champs. **Commit.**

```bash
git add app/Livewire/Questionnaire/ModeleEditor.php app/Livewire/Questionnaire/ModeleList.php resources/views/livewire/questionnaire/modele-editor.blade.php resources/views/livewire/questionnaire/modele-list.blade.php tests/Livewire/Questionnaire/ModeleEditorTest.php tests/Livewire/Questionnaire/ModeleListTest.php
git commit -m "feat(questionnaires): édition TinyMCE des messages intro/remerciement (éditeur plein écran)"
```

### Task A.2 : rendu résolu des messages (répondant)

**Files:**
- Modify: `resources/views/questionnaire/repondant/intro.blade.php`
- Modify: `resources/views/questionnaire/repondant/merci.blade.php`
- Modify: `app/Http/Controllers/QuestionnaireRepondantController.php` (passer les variables résolues aux vues intro/merci)
- Test: `tests/Feature/Questionnaire/RepondantParcoursTest.php` (ajouter un cas)

- [ ] **Step 1 : Test** (l'intro affiche le prénom résolu + le HTML)

```php
it('affiche l intro en HTML avec variables résolues', function (): void {
    [$clair, $invitation] = makeOuverteInvitation();
    $invitation->campaign->update(['intro' => '<p>Bonjour <strong>{prenom}</strong></p>']);
    $invitation->participant->tiers->update(['prenom' => 'Camille']);
    \App\Tenant\TenantContext::clear();

    $this->get("/q/{$clair}")
        ->assertOk()
        ->assertSee('Bonjour', false)
        ->assertSee('Camille', false)
        ->assertSee('<strong>', false); // HTML rendu, pas échappé
});
```

- [ ] **Step 2 : Lancer (échoue).**

- [ ] **Step 3 : Controller** — Dans `show()` (cas intro) et `merci()`, résoudre puis passer le HTML :

```php
// en tête : use App\Services\Questionnaire\QuestionnaireVariableResolver;
// injecter le resolver dans le constructeur (à côté de tokens/reponses)

// cas intro (page 0) :
$vars = $this->variables->pour($invitation);
return view('questionnaire.repondant.intro', [
    'invitation' => $invitation, 'campagne' => $campagne, 'token' => $token,
    'introHtml' => $this->variables->remplacer((string) $campagne->intro, $vars),
    'titre' => $this->variables->remplacer((string) $campagne->titre_affiche, $vars),
]);

// merci() :
$vars = $this->variables->pour($invitation);
return view('questionnaire.repondant.merci', [
    'campagne' => $invitation->campaign,
    'remerciementHtml' => $this->variables->remplacer((string) $invitation->campaign->remerciement, $vars),
    'titre' => $this->variables->remplacer((string) $invitation->campaign->titre_affiche, $vars),
]);
```

- [ ] **Step 4 : Vues** — `intro.blade.php` : afficher `{{ $titre }}` (échappé) + `{!! $introHtml !!}` (HTML assaini). `merci.blade.php` : `{!! $remerciementHtml !!}`. (Le HTML provient de `sanitizeCorps`, donc sûr.)

- [ ] **Step 5 : Lancer (passe). Commit.**

```bash
git add resources/views/questionnaire/repondant/intro.blade.php resources/views/questionnaire/repondant/merci.blade.php app/Http/Controllers/QuestionnaireRepondantController.php tests/Feature/Questionnaire/RepondantParcoursTest.php
git commit -m "feat(questionnaires): rendu HTML des messages intro/remerciement avec variables résolues"
```

**✅ Jalon A : messages personnalisés riches.**

---

## PHASE D — Commentaire optionnel sur satisfaction

### Task D.1 : config commentaire dans l'éditeur

**Files:**
- Modify: `app/Livewire/Questionnaire/ModeleEditor.php` (props `commentaire`, `commentaireLibelle` ; `buildConfig`)
- Modify: `resources/views/livewire/questionnaire/modele-editor.blade.php` (toggle + input, visibles si type=satisfaction)
- Test: `tests/Livewire/Questionnaire/ModeleEditorTest.php`

- [ ] **Step 1 : Test**

```php
it('active un commentaire optionnel sur une question satisfaction', function (): void {
    $t = \App\Models\QuestionnaireTemplate::factory()->create();

    \Livewire\Livewire::test(\App\Livewire\Questionnaire\ModeleEditor::class, ['template' => $t])
        ->set('libelle', 'Note globale')
        ->set('type', \App\Enums\TypeQuestion::Satisfaction->value)
        ->set('commentaire', true)
        ->set('commentaireLibelle', 'Pourquoi cette note ?')
        ->call('ajouterQuestion');

    $q = $t->questions()->first();
    expect($q->config['commentaire'])->toBeTrue();
    expect($q->config['commentaire_libelle'])->toBe('Pourquoi cette note ?');
});
```

- [ ] **Step 2 : Lancer (échoue).**

- [ ] **Step 3 : ModeleEditor** — Ajouter `public bool $commentaire = false;` et `public string $commentaireLibelle = '';`. Étendre `buildConfig(TypeQuestion $type)` : pour `Satisfaction` avec `$this->commentaire`, retourner `['commentaire' => true, 'commentaire_libelle' => $this->commentaireLibelle ?: 'Un commentaire ? (optionnel)']`. Reset après `ajouterQuestion`. (Conserver la logique options pour choix_unique.)

```php
// extrait de buildConfig :
if ($type === TypeQuestion::Satisfaction && $this->commentaire) {
    return [
        'commentaire' => true,
        'commentaire_libelle' => $this->commentaireLibelle !== '' ? $this->commentaireLibelle : 'Un commentaire ? (optionnel)',
    ];
}
```

- [ ] **Step 4 : Vue** — Sous le sélecteur de type, `@if ($type === 'satisfaction')` : une case `wire:model="commentaire"` + un input `wire:model="commentaireLibelle"` (placeholder « Un commentaire ? (optionnel) »).

- [ ] **Step 5 : Lancer (passe). Commit.**

```bash
git commit -am "feat(questionnaires): config commentaire optionnel sur les questions satisfaction"
```

### Task D.2 : persistance note + commentaire + obligatoire révisé

**Files:**
- Modify: `app/Services/Questionnaire/QuestionnaireReponseService.php`
- Test: `tests/Feature/Questionnaire/QuestionnaireReponseServiceTest.php`

- [ ] **Step 1 : Test**

```php
it('enregistre note et commentaire sur la même ligne', function (): void {
    $svc = app(\App\Services\Questionnaire\QuestionnaireReponseService::class);
    $campagne = \App\Models\QuestionnaireCampaign::factory()->create();
    $q = \App\Models\QuestionnaireCampaignQuestion::factory()->for($campagne, 'campaign')->create([
        'type' => \App\Enums\TypeQuestion::Satisfaction, 'ordre' => 1,
        'config' => ['commentaire' => true, 'commentaire_libelle' => 'Pourquoi ?'],
    ]);
    $inv = \App\Models\QuestionnaireInvitation::factory()->for($campagne, 'campaign')->create();
    $sub = $svc->demarrerOuReprendre($inv);

    $svc->enregistrerReponse($sub, $q, '4', commentaire: 'Très bon accueil');

    $a = $sub->fresh()->answers()->first();
    expect($a->value_integer)->toBe(4);
    expect($a->value_text)->toBe('Très bon accueil');
});

it('obligatoire : la note seule valide, le commentaire seul ne valide pas', function (): void {
    $svc = app(\App\Services\Questionnaire\QuestionnaireReponseService::class);
    $campagne = \App\Models\QuestionnaireCampaign::factory()->create();
    $q = \App\Models\QuestionnaireCampaignQuestion::factory()->for($campagne, 'campaign')->create([
        'type' => \App\Enums\TypeQuestion::Satisfaction, 'ordre' => 1, 'obligatoire' => true,
        'config' => ['commentaire' => true],
    ]);
    $inv = \App\Models\QuestionnaireInvitation::factory()->for($campagne, 'campaign')->create();
    $sub = $svc->demarrerOuReprendre($inv);

    // commentaire seul (pas de note) → bloque
    $svc->enregistrerReponse($sub, $q, null, commentaire: 'un avis');
    expect(fn () => $svc->finaliser($sub, accepteContact: false))
        ->toThrow(\App\Exceptions\Questionnaire\ReponseObligatoireException::class);

    // note fournie → passe
    $svc->enregistrerReponse($sub, $q, '5', commentaire: 'un avis');
    $svc->finaliser($sub, accepteContact: false);
    expect($sub->fresh()->statut->value)->toBe('soumise');
});
```

- [ ] **Step 2 : Lancer (échoue).**

- [ ] **Step 3 : Service** — Signature de `enregistrerReponse` : ajouter `?string $commentaire = null`. `normaliser` reçoit le commentaire et, pour `Satisfaction` avec note, pose `value_text = $commentaire`. `verifierObligatoires` change de critère (colonne primaire du type) :

```php
public function enregistrerReponse(
    QuestionnaireSubmission $submission,
    QuestionnaireCampaignQuestion $question,
    int|string|bool|null $valeurBrute,
    ?string $commentaire = null,
): void {
    $payload = $this->normaliser($question, $valeurBrute, $commentaire);
    $submission->answers()->updateOrCreate(
        ['campaign_question_id' => $question->id],
        $payload,
    );
}

private function normaliser(QuestionnaireCampaignQuestion $question, int|string|bool|null $v, ?string $commentaire = null): array
{
    $base = [
        'value_text' => null, 'value_integer' => null,
        'value_boolean' => null, 'value_option' => null, 'value_meta' => null,
    ];

    $payload = match ($question->type) {
        TypeQuestion::TexteCourt, TypeQuestion::TexteLong => ($v === null || $v === '') ? $base : [...$base, 'value_text' => (string) $v],
        TypeQuestion::Satisfaction, TypeQuestion::Ressenti => ($v === null || $v === '') ? $base : [...$base, 'value_integer' => (int) $v],
        TypeQuestion::CaseACocher => ($v === null || $v === '') ? $base : [...$base, 'value_boolean' => (bool) $v],
        TypeQuestion::ChoixUnique => ($v === null || $v === '') ? $base : [
            ...$base,
            'value_option' => (string) $v,
            'value_meta' => ['libelle' => $question->libelleOption((string) $v)],
        ],
    };

    // Commentaire optionnel (satisfaction) : stocké dans value_text, indépendamment de la note.
    if ($question->type === TypeQuestion::Satisfaction
        && ($question->config['commentaire'] ?? false)
        && $commentaire !== null && $commentaire !== '') {
        $payload['value_text'] = $commentaire;
    }

    return $payload;
}

private function verifierObligatoires(QuestionnaireSubmission $submission): void
{
    $answers = $submission->answers()->get()->keyBy('campaign_question_id');

    $manquante = $submission->campaign->questions()
        ->where('obligatoire', true)
        ->get()
        ->first(function ($q) use ($answers): bool {
            $col = $q->type->valueColumn();           // colonne primaire du type
            $a = $answers->get($q->id);
            return $a === null || $a->{$col} === null; // primaire absente = non répondue
        });

    if ($manquante !== null) {
        throw new ReponseObligatoireException('Une question obligatoire n\'est pas renseignée.');
    }
}
```

Note : `valueColumn()` existe déjà sur `TypeQuestion` (texte→value_text, satisfaction/ressenti→value_integer, case→value_boolean, choix→value_option).

- [ ] **Step 4 : Lancer (passe).** Re-lancer tout `QuestionnaireReponseServiceTest` (la révision de `verifierObligatoires` ne doit pas casser les cas existants). **Commit.**

```bash
git commit -am "feat(questionnaires): commentaire satisfaction (même ligne) + obligatoire sur colonne primaire"
```

### Task D.3 : le controller lit le commentaire

**Files:**
- Modify: `app/Http/Controllers/QuestionnaireRepondantController.php`
- Test: `tests/Feature/Questionnaire/RepondantParcoursTest.php`

- [ ] **Step 1 : Test** (POST avec note + commentaire persiste les deux)

```php
it('le parcours enregistre le commentaire de satisfaction', function (): void {
    $op = \App\Models\Operation::factory()->create();
    $participant = \App\Models\Participant::factory()->create(['operation_id' => $op->id]);
    $campagne = \App\Models\QuestionnaireCampaign::factory()->for($op, 'operation')->create(['statut' => 'ouverte']);
    $q = \App\Models\QuestionnaireCampaignQuestion::factory()->for($campagne, 'campaign')->create([
        'type' => 'satisfaction', 'ordre' => 1, 'config' => ['commentaire' => true],
    ]);
    $clair = \Illuminate\Support\Str::random(48);
    $inv = \App\Models\QuestionnaireInvitation::factory()->for($campagne, 'campaign')->create([
        'participant_id' => $participant->id,
        'token_hash' => app(\App\Services\Questionnaire\QuestionnaireTokenService::class)->hash($clair),
    ]);
    \App\Tenant\TenantContext::clear();

    $this->post("/q/{$clair}", ['action' => 'start']);
    $this->post("/q/{$clair}", ['action' => 'next', 'page' => 1, "q_{$q->id}" => '4', "q_{$q->id}_commentaire" => 'RAS positif']);

    $a = $inv->fresh()->submissions()->first()->answers()->first();
    expect($a->value_integer)->toBe(4);
    expect($a->value_text)->toBe('RAS positif');
});
```

- [ ] **Step 2 : Lancer (échoue).**

- [ ] **Step 3 : Controller** — Dans `store()` action `next`, lire le commentaire et le passer :

```php
$valeur = $request->input("q_{$question->id}");
$commentaire = $request->input("q_{$question->id}_commentaire");
// … garde obligatoire inchangée (sur $valeur) …
$this->reponses->enregistrerReponse($submission, $question, $valeur, $commentaire);
```

- [ ] **Step 4 : Lancer (passe). Commit.**

```bash
git commit -am "feat(questionnaires): le parcours capture le commentaire de satisfaction"
```

### Task D.4 : champ commentaire dans le rendu question

**Files:**
- Modify: `resources/views/questionnaire/repondant/question.blade.php`

- [ ] **Step 1 : Modifier** — Dans le `@case('satisfaction')`, après les 5 radios, ajouter :

```blade
@if ($question->config['commentaire'] ?? false)
    <div class="mt-3">
        <label class="form-label small text-muted" for="{{ $fieldName }}_commentaire">
            {{ $question->config['commentaire_libelle'] ?? 'Un commentaire ? (optionnel)' }}
        </label>
        <textarea class="form-control" rows="2"
                  id="{{ $fieldName }}_commentaire"
                  name="{{ $fieldName }}_commentaire">{{ old("{$fieldName}_commentaire", $answer?->value_text) }}</textarea>
    </div>
@endif
```

- [ ] **Step 2 : Vérifier** que `RepondantParcoursTest` (incl. le test D.3) passe. **Commit.**

```bash
git commit -am "feat(questionnaires): textarea commentaire sous la satisfaction (répondant)"
```

### Task D.5 : verbatims satisfaction aux résultats

**Files:**
- Modify: `app/Services/Questionnaire/QuestionnaireResultatService.php`
- Modify: `resources/views/livewire/questionnaire/campagne-resultats.blade.php`
- Test: `tests/Feature/Questionnaire/QuestionnaireResultatServiceTest.php`

- [ ] **Step 1 : Test** (la satisfaction commentée expose les verbatims)

```php
it('agrège les commentaires d une satisfaction commentée', function (): void {
    $campagne = \App\Models\QuestionnaireCampaign::factory()->create(['statut' => 'ouverte']);
    $q = \App\Models\QuestionnaireCampaignQuestion::factory()->for($campagne, 'campaign')->create([
        'type' => \App\Enums\TypeQuestion::Satisfaction, 'ordre' => 1, 'config' => ['commentaire' => true],
    ]);
    $svc = app(\App\Services\Questionnaire\QuestionnaireReponseService::class);
    $inv = \App\Models\QuestionnaireInvitation::factory()->for($campagne, 'campaign')->create();
    $sub = $svc->demarrerOuReprendre($inv);
    $svc->enregistrerReponse($sub, $q, '5', commentaire: 'Parfait');
    $svc->finaliser($sub, accepteContact: false);

    $res = app(\App\Services\Questionnaire\QuestionnaireResultatService::class)->pourCampagne($campagne->fresh());

    expect($res['questions'][0]['moyenne'])->toBe(5.0);
    expect($res['questions'][0]['verbatims'])->toContain('Parfait');
});
```

- [ ] **Step 2 : Lancer (échoue).**

- [ ] **Step 3 : Service** — Dans `agreger()`, pour `Satisfaction`/`Ressenti`, si la question a `config['commentaire']`, ajouter `'verbatims' => $answers->pluck('value_text')->filter()->values()->all()`. (Le `$question` est déjà passé à `agreger`.)

- [ ] **Step 4 : Vue résultats** — Sous la stat d'une satisfaction, si `$q['verbatims'] ?? []` non vide, lister les commentaires.

- [ ] **Step 5 : Lancer (passe). Commit.**

```bash
git commit -am "feat(questionnaires): verbatims des commentaires de satisfaction aux résultats"
```

### Task D.6 : export 2 colonnes

**Files:**
- Modify: `app/Services/Questionnaire/QuestionnaireExcelExporter.php`
- Test: `tests/Feature/Questionnaire/QuestionnaireExcelExporterTest.php`

- [ ] **Step 1 : Test** (en-tête + valeur du commentaire)

```php
it('exporte deux colonnes pour une satisfaction commentée', function (): void {
    $campagne = \App\Models\QuestionnaireCampaign::factory()->create(['statut' => 'ouverte']);
    $q = \App\Models\QuestionnaireCampaignQuestion::factory()->for($campagne, 'campaign')->create([
        'libelle' => 'Note', 'type' => \App\Enums\TypeQuestion::Satisfaction, 'ordre' => 1,
        'config' => ['commentaire' => true],
    ]);
    $svc = app(\App\Services\Questionnaire\QuestionnaireReponseService::class);
    $inv = \App\Models\QuestionnaireInvitation::factory()->for($campagne, 'campaign')->create();
    $sub = $svc->demarrerOuReprendre($inv);
    $svc->enregistrerReponse($sub, $q, '4', commentaire: 'Bien');
    $svc->finaliser($sub, accepteContact: false);

    $rows = app(\App\Services\Questionnaire\QuestionnaireExcelExporter::class)->lignes($campagne->fresh());

    expect($rows[0])->toContain('Note');
    expect($rows[0])->toContain('Note — commentaire');
    expect($rows[1])->toContain(4);
    expect($rows[1])->toContain('Bien');
});
```

- [ ] **Step 2 : Lancer (échoue).**

- [ ] **Step 3 : Exporter** — Construction de l'en-tête : pour chaque question, si `Satisfaction` + `config['commentaire']`, émettre **2 en-têtes** (`$q->libelle` puis `$q->libelle.' — commentaire'`). Construction des lignes : émettre la valeur (value_integer) puis, pour ces questions, la valeur `value_text` (commentaire). Garder l'ordre cohérent entre en-tête et données.

```php
// en-têtes : à la place de `foreach ($questions as $q) { $entetes[] = $q->libelle; }`
foreach ($questions as $q) {
    $entetes[] = $q->libelle;
    if ($q->type === TypeQuestion::Satisfaction && ($q->config['commentaire'] ?? false)) {
        $entetes[] = $q->libelle.' — commentaire';
    }
}

// lignes : dans la boucle des questions
foreach ($questions as $q) {
    $answer = $answersParQ->get($q->id);
    $ligne[] = $this->valeurAffichee($q->type, $answer, $q);
    if ($q->type === TypeQuestion::Satisfaction && ($q->config['commentaire'] ?? false)) {
        $ligne[] = $answer?->value_text ?? '';
    }
}
```

- [ ] **Step 4 : Lancer (passe). Commit.**

```bash
git commit -am "feat(questionnaires): export 2 colonnes (note + commentaire) pour satisfaction commentée"
```

**✅ Jalon D : satisfaction commentée de bout en bout.**

---

## PHASE B — Mode aperçu

### Task B.1 : extraire le partial de champ partagé

**Files:**
- Create: `resources/views/questionnaire/repondant/partials/champ.blade.php`
- Modify: `resources/views/questionnaire/repondant/question.blade.php` (utiliser le partial)

- [ ] **Step 1 : Créer le partial** — Déplacer le `@switch($question->type->value)` complet (les 6 cas + le commentaire satisfaction de D.4) de `question.blade.php` vers `partials/champ.blade.php`. Le partial attend `$question`, `$fieldName`, `$oldValue`, et `$answer` (pour le commentaire). Le rendu d'un champ y est **identique** quel que soit le mode (réel ou aperçu).

- [ ] **Step 2 : `question.blade.php`** — Remplacer le `@switch` par `@include('questionnaire.repondant.partials.champ', ['question' => $question, 'fieldName' => $fieldName, 'oldValue' => $oldValue, 'answer' => $answer])`.

- [ ] **Step 3 : Vérifier** que `RepondantParcoursTest` reste vert (le parcours réel rend toujours les champs). **Commit.**

```bash
git add resources/views/questionnaire/repondant/partials/champ.blade.php resources/views/questionnaire/repondant/question.blade.php
git commit -m "refactor(questionnaires): partial de champ partagé pour le parcours répondant"
```

### Task B.2 : controller + vues d'aperçu

**Files:**
- Create: `app/Http/Controllers/QuestionnaireApercuController.php`
- Create: `resources/views/questionnaire/apercu/{intro,question,consentement,merci}.blade.php`
- Modify: `routes/web.php` (2 routes aperçu dans le groupe `questionnaires.*`)
- Test: `tests/Feature/Questionnaire/QuestionnaireApercuTest.php`

- [ ] **Step 1 : Test** (aperçu OK, AUCUNE soumission créée)

```php
<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\Operation;
use App\Models\QuestionnaireCampaign;
use App\Models\QuestionnaireCampaignQuestion;
use App\Models\QuestionnaireSubmission;
use App\Models\QuestionnaireTemplate;
use App\Models\QuestionnaireTemplateQuestion;
use App\Models\User;
use App\Tenant\TenantContext;

beforeEach(function () {
    $this->association = Association::factory()->create();
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
    $this->actingAs($this->user);
});
afterEach(fn () => TenantContext::clear());

it('prévisualise un modèle sans rien enregistrer', function (): void {
    $t = QuestionnaireTemplate::factory()->create(['titre_affiche' => 'Votre avis']);
    QuestionnaireTemplateQuestion::factory()->for($t, 'template')->create(['libelle' => 'Note', 'type' => 'satisfaction', 'ordre' => 1]);

    $this->get(route('questionnaires.modeles.apercu', $t))
        ->assertOk()
        ->assertSee('Mode aperçu', false)
        ->assertSee('Votre avis', false);

    $this->get(route('questionnaires.modeles.apercu', ['template' => $t->id, 'page' => 1]))
        ->assertOk()
        ->assertSee('Note', false);

    expect(QuestionnaireSubmission::count())->toBe(0);
});

it('prévisualise une campagne avec variables d exemple résolues sur l opération', function (): void {
    $op = Operation::factory()->create(['nom' => 'Atelier démo']);
    $campagne = QuestionnaireCampaign::factory()->for($op, 'operation')->create(['intro' => '<p>Pour {operation}</p>']);
    QuestionnaireCampaignQuestion::factory()->for($campagne, 'campaign')->create(['type' => 'texte_court', 'ordre' => 1]);

    $this->get(route('questionnaires.campagnes.apercu', $campagne))
        ->assertOk()
        ->assertSee('Atelier démo', false); // {operation} résolu sur la vraie opération

    expect(QuestionnaireSubmission::count())->toBe(0);
});
```

- [ ] **Step 2 : Lancer (échoue).**

- [ ] **Step 3 : Controller** — `QuestionnaireApercuController` (admin auth, tenant déjà booté). Deux entrées : `modele(QuestionnaireTemplate $template, Request)` et `campagne(QuestionnaireCampaign $campagne, Request)`. Chacune construit une « source » uniforme (titre/intro/remerciement + questions ordonnées) et un jeu de variables d'exemple via `QuestionnaireVariableResolver::exemple($campagne ?? null)`, puis rend les vues d'aperçu page par page (`?page=N`, GET). **Aucune** création de submission/answer.

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\QuestionnaireCampaign;
use App\Models\QuestionnaireTemplate;
use App\Services\Questionnaire\QuestionnaireVariableResolver;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

final class QuestionnaireApercuController extends Controller
{
    public function __construct(private readonly QuestionnaireVariableResolver $variables) {}

    public function modele(Request $request, QuestionnaireTemplate $template): View
    {
        return $this->rendre(
            $request,
            titre: (string) $template->titre_affiche,
            intro: (string) $template->intro,
            remerciement: (string) $template->remerciement,
            questions: $template->questions()->get(),
            vars: $this->variables->exemple(),
            retour: route('questionnaires.modeles.editor', $template),
            base: route('questionnaires.modeles.apercu', $template),
        );
    }

    public function campagne(Request $request, QuestionnaireCampaign $campagne): View
    {
        return $this->rendre(
            $request,
            titre: (string) $campagne->titre_affiche,
            intro: (string) $campagne->intro,
            remerciement: (string) $campagne->remerciement,
            questions: $campagne->questions()->get(),
            vars: $this->variables->exemple($campagne),
            retour: route('operations.show', $campagne->operation_id),
            base: route('questionnaires.campagnes.apercu', $campagne),
        );
    }

    /**
     * @param  \Illuminate\Support\Collection<int, \App\Models\QuestionnaireCampaignQuestion|\App\Models\QuestionnaireTemplateQuestion>  $questions
     * @param  array<string, string>  $vars
     */
    private function rendre(Request $request, string $titre, string $intro, string $remerciement, $questions, array $vars, string $retour, string $base): View
    {
        $page = $request->query('page', '0');
        $total = $questions->count();

        $commun = [
            'titre' => $this->variables->remplacer($titre, $vars),
            'base' => $base, 'retour' => $retour, 'total' => $total,
        ];

        if ($page === 'consentement') {
            return view('questionnaire.apercu.consentement', $commun + ['page' => $total + 1]);
        }
        if ($page === 'merci') {
            return view('questionnaire.apercu.merci', $commun + ['remerciementHtml' => $this->variables->remplacer($remerciement, $vars)]);
        }

        $page = max(0, (int) $page);
        if ($page === 0) {
            return view('questionnaire.apercu.intro', $commun + ['introHtml' => $this->variables->remplacer($intro, $vars)]);
        }

        $question = $questions[$page - 1] ?? null;
        abort_if($question === null, 404);

        return view('questionnaire.apercu.question', $commun + ['question' => $question, 'page' => $page]);
    }
}
```

- [ ] **Step 4 : Routes** (groupe `questionnaires.*`) :

```php
Route::get('/modeles/{template}/apercu', [\App\Http\Controllers\QuestionnaireApercuController::class, 'modele'])->name('modeles.apercu');
Route::get('/campagnes/{campagne}/apercu', [\App\Http\Controllers\QuestionnaireApercuController::class, 'campagne'])->name('campagnes.apercu');
```

- [ ] **Step 5 : Vues d'aperçu** — Réutiliser le layout `questionnaire.repondant.layout`. Bandeau permanent `<div class="alert alert-warning">Mode aperçu — aucune réponse n'est enregistrée.</div>`. `intro` : titre + `{!! $introHtml !!}` + lien GET « Commencer » → `{{ $base }}?page=1`. `question` : barre de progression + `@include('questionnaire.repondant.partials.champ', ['question' => $question, 'fieldName' => "q_{$question->id}", 'oldValue' => null, 'answer' => null])` + lien GET « Suivant » → `?page=N+1` (si N+1>total → `?page=consentement`). `consentement` : case (cosmétique) + lien GET « Terminer » → `?page=merci`. `merci` : `{!! $remerciementHtml !!}` + lien « Retour » → `{{ $retour }}`. **Aucun `<form method=POST>`**.

- [ ] **Step 6 : Lancer (passe). Commit.**

```bash
git add app/Http/Controllers/QuestionnaireApercuController.php resources/views/questionnaire/apercu routes/web.php tests/Feature/Questionnaire/QuestionnaireApercuTest.php
git commit -m "feat(questionnaires): mode aperçu (modèle + campagne) sans enregistrement"
```

### Task B.3 : boutons « Prévisualiser »

**Files:**
- Modify: `resources/views/livewire/questionnaire/modele-editor.blade.php` (bouton vers `questionnaires.modeles.apercu`)
- Modify: `resources/views/livewire/questionnaire/operation-questionnaires.blade.php` (lien aperçu par campagne)

- [ ] **Step 1 : Ajouter** un bouton « Prévisualiser » (lien `target="_blank"`) sur l'éditeur (`route('questionnaires.modeles.apercu', $template)`) et sur chaque ligne de campagne (`route('questionnaires.campagnes.apercu', $c)`). **Step 2 : Commit.**

```bash
git commit -am "feat(questionnaires): boutons Prévisualiser (modèle + campagne)"
```

**✅ Jalon B : tester le parcours sans enregistrer.**

---

## PHASE C — Envoi par la messagerie

### Task C.1 : `QuestionnaireInvitationMail`

**Files:**
- Create: `app/Mail/QuestionnaireInvitationMail.php`
- Create: `resources/views/emails/questionnaire-invitation.blade.php`
- Test: `tests/Feature/Questionnaire/QuestionnaireInvitationMailTest.php`

Le Mailable est **mince** : il reçoit l'objet et le corps **déjà résolus + assainis** (la résolution des variables se fait dans le service C.2). Mirrorer `app/Mail/MessageLibreMail.php` pour le logo CID (`cid:logo-asso`) et l'enveloppe.

- [ ] **Step 1 : Test**

```php
<?php

declare(strict_types=1);

use App\Mail\QuestionnaireInvitationMail;

it('rend l objet et le corps fournis', function (): void {
    $mail = new QuestionnaireInvitationMail(objet: 'Votre avis', corpsHtml: '<p>Bonjour Marie, <a href="https://x/q/abc">répondez</a></p>');
    $rendu = $mail->render();

    expect($rendu)->toContain('Bonjour Marie');
    expect($rendu)->toContain('https://x/q/abc');
});
```

- [ ] **Step 2 : Lancer (échoue).**

- [ ] **Step 3 : Mailable** — `__construct(public readonly string $objet, public readonly string $corpsHtml, public readonly ?string $trackingToken = null)`. `envelope()` → subject = objet. `content()` → view `emails.questionnaire-invitation` avec `$corpsHtml`. Logo CID comme `MessageLibreMail`. La vue affiche `{!! $corpsHtml !!}` dans l'enveloppe HTML (header logo + footer).

- [ ] **Step 4 : Lancer (passe). Commit.**

```bash
git add app/Mail/QuestionnaireInvitationMail.php resources/views/emails/questionnaire-invitation.blade.php tests/Feature/Questionnaire/QuestionnaireInvitationMailTest.php
git commit -m "feat(questionnaires): Mailable invitation (objet + corps résolu)"
```

### Task C.2 : `QuestionnaireEnvoiService`

**Files:**
- Create: `app/Services/Questionnaire/QuestionnaireEnvoiService.php`
- Test: `tests/Feature/Questionnaire/QuestionnaireEnvoiServiceTest.php`

- [ ] **Step 1 : Test**

```php
<?php

declare(strict_types=1);

use App\Mail\QuestionnaireInvitationMail;
use App\Models\EmailLog;
use App\Models\Operation;
use App\Models\Participant;
use App\Models\QuestionnaireCampaign;
use App\Models\QuestionnaireInvitation;
use App\Services\Questionnaire\QuestionnaireEnvoiService;
use Illuminate\Support\Facades\Mail;

it('envoie aux invitations ciblées, résout le lien, journalise, pose sent_at', function (): void {
    Mail::fake();
    $op = Operation::factory()->create();
    $participant = Participant::factory()->create(['operation_id' => $op->id]);
    $participant->tiers->update(['email' => 'marie@example.test', 'prenom' => 'Marie']);
    $campagne = QuestionnaireCampaign::factory()->for($op, 'operation')->create(['statut' => 'ouverte']);
    $inv = QuestionnaireInvitation::factory()->for($campagne, 'campaign')->create(['participant_id' => $participant->id]);

    app(QuestionnaireEnvoiService::class)->envoyer($campagne, [$inv->id], 'Objet {prenom}', 'Lien : {lien_questionnaire}');

    Mail::assertSent(QuestionnaireInvitationMail::class, 1);
    expect(EmailLog::count())->toBe(1);
    expect($inv->fresh()->sent_at)->not->toBeNull();
});

it('ne vise que les non soumis pour une relance', function (): void {
    $campagne = QuestionnaireCampaign::factory()->create(['statut' => 'ouverte']);
    QuestionnaireInvitation::factory()->for($campagne, 'campaign')->create(['statut' => 'soumis']);
    QuestionnaireInvitation::factory()->for($campagne, 'campaign')->create(['statut' => 'non_ouvert']);

    expect(app(QuestionnaireEnvoiService::class)->idsNonSoumis($campagne))->toHaveCount(1);
});
```

- [ ] **Step 2 : Lancer (échoue).**

- [ ] **Step 3 : Service** — `envoyer(campagne, array $invitationIds, string $objet, string $corps)` : assainir le corps une fois (`EmailTemplate::sanitizeCorps`), puis par invitation : résoudre (`QuestionnaireVariableResolver::pour($inv, avecLien:true)`), `remplacer` objet+corps, envoyer `QuestionnaireInvitationMail`, `EmailLog::create([...])` (mirrorer les champs d'`OperationCommunication` : `categorie => 'message'`, `operation_id`, `participant_id`, `tiers_id`, `destinataire_email/nom`, `objet`, `corps_html`, `statut => 'envoye'`, `envoye_par => Auth::id()` ; **laisser `campagne_id` à null** — ce champ référence `campagne_emails`, pas la campagne questionnaire), `invitation->update(['sent_at' => now()])`. Participant sans email → skip. `idsNonSoumis(campagne)` → invitations `statut != 'soumis'`.

- [ ] **Step 4 : Lancer (passe). Commit.**

```bash
git add app/Services/Questionnaire/QuestionnaireEnvoiService.php tests/Feature/Questionnaire/QuestionnaireEnvoiServiceTest.php
git commit -m "feat(questionnaires): QuestionnaireEnvoiService (envoi + EmailLog + relances)"
```

### Task C.3 : compositeur Livewire + boutons

**Files:**
- Create: `app/Livewire/Questionnaire/EnvoiCompose.php`
- Create: `resources/views/livewire/questionnaire/envoi-compose.blade.php`
- Modify: `app/Livewire/Questionnaire/OperationQuestionnaires.php` + sa vue (ouvrir le compositeur)
- Test: `tests/Livewire/Questionnaire/EnvoiComposeTest.php`

- [ ] **Step 1 : Test** (`Mail::fake()` ; envoi via le compositeur)

```php
<?php

declare(strict_types=1);

use App\Livewire\Questionnaire\EnvoiCompose;
use App\Mail\QuestionnaireInvitationMail;
use App\Models\Operation;
use App\Models\Participant;
use App\Models\QuestionnaireCampaign;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

it('envoie les invitations aux participants sélectionnés', function (): void {
    Mail::fake();
    $op = Operation::factory()->create();
    $p = Participant::factory()->create(['operation_id' => $op->id]);
    $p->tiers->update(['email' => 'p@example.test']);
    $campagne = QuestionnaireCampaign::factory()->for($op, 'operation')->create(['statut' => 'ouverte']);

    Livewire::test(EnvoiCompose::class, ['campagne' => $campagne])
        ->set('objet', 'Votre avis')
        ->set('corps', 'Lien : {lien_questionnaire}')
        ->set('selectedParticipants', [$p->id])
        ->call('envoyer')
        ->assertHasNoErrors();

    Mail::assertSent(QuestionnaireInvitationMail::class, 1);
});
```

- [ ] **Step 2 : Lancer (échoue).**

- [ ] **Step 3 : Composant `EnvoiCompose`** — Props : `QuestionnaireCampaign $campagne`, `string $objet`, `string $corps` (défaut seedé, cf. C.4), `array $selectedParticipants` (mount : tous les participants ; en mode relance, `idsNonSoumis`). `envoyer()` : génère les invitations manquantes (`QuestionnaireInvitationService::genererPour`), récupère leurs ids, puis `QuestionnaireEnvoiService::envoyer($campagne, $ids, $objet, $corps)`. `relancer()` : présélectionne les non-soumis.

- [ ] **Step 4 : Vue compositeur** — objet (input) + corps **TinyMCE** (mirrorer `operation-communication.blade.php`) + bouton « Insérer une variable » (inclut `{lien_questionnaire}`) + cases participants + bouton « Envoyer ». Intégrer le composant dans `OperationQuestionnaires` (modale ou section dépliable) via un bouton « Envoyer les invitations » / « Relancer les non-répondants » sur la campagne `ouverte`.

- [ ] **Step 5 : Lancer (passe). Commit.**

```bash
git add app/Livewire/Questionnaire/EnvoiCompose.php resources/views/livewire/questionnaire/envoi-compose.blade.php app/Livewire/Questionnaire/OperationQuestionnaires.php resources/views/livewire/questionnaire/operation-questionnaires.blade.php tests/Livewire/Questionnaire/EnvoiComposeTest.php
git commit -m "feat(questionnaires): compositeur d'envoi des invitations + relances"
```

### Task C.4 : corps email par défaut seedé

**Files:**
- Modify: `app/Livewire/Questionnaire/EnvoiCompose.php` (constante `CORPS_DEFAUT`)

- [ ] **Step 1 : Définir** un `CORPS_DEFAUT` HTML court avec variables (`Bonjour {prenom}, … {lien_questionnaire} …`) utilisé comme valeur initiale de `$corps` dans `mount()`. **Step 2 : Commit.**

```bash
git commit -am "feat(questionnaires): corps email par défaut pour l'envoi des invitations"
```

> **Dette tech (actée)** : la sauvegarde/réutilisation de **gabarits d'email questionnaire** est hors périmètre — corps en composition libre, seedé par défaut. À reprendre via `EmailTemplate`/`MessageTemplate`.

**✅ Jalon C : invitations envoyées par la messagerie + relances.**

---

## Vérification finale

- [ ] Suite ciblée verte : `php -d memory_limit=-1 vendor/bin/pest --parallel tests/Feature/Questionnaire tests/Livewire/Questionnaire tests/Unit/Enums`
- [ ] `./vendor/bin/pint` sur les fichiers créés/modifiés.
- [ ] Recette manuelle localhost (navigateur) : éditer intro/remerciement riches + variables → **Prévisualiser** modèle puis campagne (vérifier **aucune** réponse dans les résultats) → activer un commentaire sur une satisfaction, répondre, vérifier verbatim aux résultats + **2 colonnes** à l'export → **Envoyer les invitations** (email reçu, lien correct) → **Relancer** (ne vise que les non-répondants).
- [ ] **Ne pas merger `main` avant validation visuelle** (cf. `feedback_test_before_push`).

## Notes pour l'exécutant

- **Réutiliser** : `EmailTemplate::sanitizeCorps()` (assainissement + protection `{var}` en href), le pattern TinyMCE+Alpine d'`operation-communication.blade.php`, les champs `EmailLog::create` d'`OperationCommunication`, le logo CID de `MessageLibreMail`.
- **Anti-injection** : `QuestionnaireVariableResolver::remplacer` échappe les valeurs ; le HTML de structure vient de `sanitizeCorps`. Ne jamais `{!! !!}` un contenu non assaini.
- **Aperçu** : zéro écriture en base — le partial `champ` est partagé mais les vues d'aperçu n'ont **pas** de `<form POST>` ni d'appel service.
- **`EmailLog.campagne_id`** référence `campagne_emails` (pas la campagne questionnaire) → laisser null.
- **Vérifier** les accesseurs civilité réels sur `Tiers`/`Civilite` avant de figer la map du resolver.
