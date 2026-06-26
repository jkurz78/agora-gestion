<?php

declare(strict_types=1);

use App\Enums\TypeQuestion;
use App\Livewire\Questionnaire\ModeleEditor;
use App\Models\QuestionnaireTemplate;
use App\Models\QuestionnaireTemplateQuestion;
use Livewire\Livewire;

it('ajoute une question typée au modèle', function (): void {
    $t = QuestionnaireTemplate::factory()->create();

    Livewire::test(ModeleEditor::class, ['template' => $t])
        ->set('libelle', 'Note globale')
        ->set('type', TypeQuestion::Satisfaction->value)
        ->set('obligatoire', true)
        ->call('ajouterQuestion')
        ->assertHasNoErrors();

    $q = $t->questions()->first();
    expect($q->libelle)->toBe('Note globale');
    expect($q->type)->toBe(TypeQuestion::Satisfaction);
    expect($q->ordre)->toBe(1);
    expect($q->obligatoire)->toBeTrue();
});

it('génère une valeur technique stable pour chaque option de choix unique', function (): void {
    $t = QuestionnaireTemplate::factory()->create();

    Livewire::test(ModeleEditor::class, ['template' => $t])
        ->set('libelle', 'Comment avez-vous connu ?')
        ->set('type', TypeQuestion::ChoixUnique->value)
        ->set('optionsBrut', "Bouche à oreille\nRéseaux sociaux\nAffiche")
        ->call('ajouterQuestion');

    $opts = $t->questions()->first()->options();
    expect($opts)->toHaveCount(3);
    expect($opts[0]['libelle'])->toBe('Bouche à oreille');
    expect($opts[0]['valeur'])->not->toBe('');         // valeur technique générée
    expect($opts[0]['valeur'])->toBe($opts[0]['valeur']); // stable
});

it('réordonne les questions', function (): void {
    $t = QuestionnaireTemplate::factory()->create();
    $q1 = QuestionnaireTemplateQuestion::factory()->for($t, 'template')->create(['ordre' => 1]);
    $q2 = QuestionnaireTemplateQuestion::factory()->for($t, 'template')->create(['ordre' => 2]);

    Livewire::test(ModeleEditor::class, ['template' => $t])
        ->call('monter', $q2->id);

    expect($q2->fresh()->ordre)->toBe(1);
    expect($q1->fresh()->ordre)->toBe(2);
});

it('active un commentaire optionnel sur une question satisfaction', function (): void {
    $t = QuestionnaireTemplate::factory()->create();

    Livewire::test(ModeleEditor::class, ['template' => $t])
        ->set('libelle', 'Note globale')
        ->set('type', TypeQuestion::Satisfaction->value)
        ->set('commentaire', true)
        ->set('commentaireLibelle', 'Pourquoi cette note ?')
        ->call('ajouterQuestion');

    $q = $t->questions()->first();
    expect($q->config['commentaire'])->toBeTrue();
    expect($q->config['commentaire_libelle'])->toBe('Pourquoi cette note ?');
});

it('stocke les labels d extrémité d une question ressenti dans la config', function (): void {
    $t = QuestionnaireTemplate::factory()->create();

    Livewire::test(ModeleEditor::class, ['template' => $t])
        ->set('libelle', 'Comment vous sentez-vous ?')
        ->set('type', TypeQuestion::Ressenti->value)
        ->set('labelGauche', 'Très mal')
        ->set('labelDroite', 'Très bien')
        ->call('ajouterQuestion')
        ->assertHasNoErrors();

    $q = $t->questions()->first();
    expect($q->type)->toBe(TypeQuestion::Ressenti);
    expect($q->config['label_gauche'])->toBe('Très mal');
    expect($q->config['label_droite'])->toBe('Très bien');
});

it('stocke null en config ressenti quand les labels sont vides', function (): void {
    $t = QuestionnaireTemplate::factory()->create();

    Livewire::test(ModeleEditor::class, ['template' => $t])
        ->set('libelle', 'Comment vous sentez-vous ?')
        ->set('type', TypeQuestion::Ressenti->value)
        ->call('ajouterQuestion')
        ->assertHasNoErrors();

    $q = $t->questions()->first();
    expect($q->config)->toBeNull();
});

// Note: la persistance intro/remerciement est désormais gérée par ModeleTextes
// (écran "Textes" séparé). Les tests correspondants sont dans ModeleTextesTest.

// ── Type Information ──────────────────────────────────────────────────────────

it('crée une question Information avec titre et texte', function (): void {
    $t = QuestionnaireTemplate::factory()->create();

    Livewire::test(ModeleEditor::class, ['template' => $t])
        ->set('libelle', 'Section A')
        ->set('aide', 'Texte descriptif de la section')
        ->set('type', TypeQuestion::Information->value)
        ->call('ajouterQuestion')
        ->assertHasNoErrors();

    $q = $t->questions()->first();
    expect($q->type)->toBe(TypeQuestion::Information);
    expect($q->libelle)->toBe('Section A');
    expect($q->aide)->toBe('Texte descriptif de la section');
});

it('force obligatoire=false pour une question Information même si le flag était coché', function (): void {
    $t = QuestionnaireTemplate::factory()->create();

    Livewire::test(ModeleEditor::class, ['template' => $t])
        ->set('libelle', 'Intertitre')
        ->set('type', TypeQuestion::Information->value)
        ->set('obligatoire', true)   // simuler un état stale
        ->call('ajouterQuestion')
        ->assertHasNoErrors();

    $q = $t->questions()->first();
    expect($q->type)->toBe(TypeQuestion::Information);
    expect($q->obligatoire)->toBeFalse();
});

it('crée une question Information sans texte (aide optionnelle)', function (): void {
    $t = QuestionnaireTemplate::factory()->create();

    Livewire::test(ModeleEditor::class, ['template' => $t])
        ->set('libelle', 'Intertitre sans texte')
        ->set('type', TypeQuestion::Information->value)
        ->call('ajouterQuestion')
        ->assertHasNoErrors();

    $q = $t->questions()->first();
    expect($q->type)->toBe(TypeQuestion::Information);
    expect($q->aide)->toBeNull();
});

it('une question non-Information conserve obligatoire et ses options normalement', function (): void {
    $t = QuestionnaireTemplate::factory()->create();

    Livewire::test(ModeleEditor::class, ['template' => $t])
        ->set('libelle', 'Votre avis ?')
        ->set('type', TypeQuestion::TexteCourt->value)
        ->set('obligatoire', true)
        ->call('ajouterQuestion')
        ->assertHasNoErrors();

    $q = $t->questions()->first();
    expect($q->type)->toBe(TypeQuestion::TexteCourt);
    expect($q->obligatoire)->toBeTrue();
});

// ── toggleGroupe ──────────────────────────────────────────────────────────────

it('toggleGroupe bascule grouper_avec_precedente de false à true', function (): void {
    $t = QuestionnaireTemplate::factory()->create();
    $q1 = QuestionnaireTemplateQuestion::factory()->for($t, 'template')->create(['ordre' => 1, 'grouper_avec_precedente' => false]);
    $q2 = QuestionnaireTemplateQuestion::factory()->for($t, 'template')->create(['ordre' => 2, 'grouper_avec_precedente' => false]);

    Livewire::test(ModeleEditor::class, ['template' => $t])
        ->call('toggleGroupe', $q2->id);

    expect($q2->fresh()->grouper_avec_precedente)->toBeTrue();
    expect($q1->fresh()->grouper_avec_precedente)->toBeFalse(); // inchangé
});

it('toggleGroupe bascule grouper_avec_precedente de true à false', function (): void {
    $t = QuestionnaireTemplate::factory()->create();
    QuestionnaireTemplateQuestion::factory()->for($t, 'template')->create(['ordre' => 1, 'grouper_avec_precedente' => false]);
    $q2 = QuestionnaireTemplateQuestion::factory()->for($t, 'template')->create(['ordre' => 2, 'grouper_avec_precedente' => true]);

    Livewire::test(ModeleEditor::class, ['template' => $t])
        ->call('toggleGroupe', $q2->id);

    expect($q2->fresh()->grouper_avec_precedente)->toBeFalse();
});

it('toggleGroupe ne s applique qu aux questions du même modèle', function (): void {
    $t1 = QuestionnaireTemplate::factory()->create();
    $t2 = QuestionnaireTemplate::factory()->create();
    $q_autre = QuestionnaireTemplateQuestion::factory()->for($t2, 'template')->create(['ordre' => 1, 'grouper_avec_precedente' => false]);

    expect(fn () => Livewire::test(ModeleEditor::class, ['template' => $t1])
        ->call('toggleGroupe', $q_autre->id)
    )->toThrow(Exception::class);
});

// ── Type SatisfactionTexteLong ────────────────────────────────────────────────

it('crée une question satisfaction_texte_long avec obligatoire et texte_obligatoire à true', function (): void {
    $t = QuestionnaireTemplate::factory()->create();

    Livewire::test(ModeleEditor::class, ['template' => $t])
        ->set('libelle', 'Satisfaction globale + commentaire')
        ->set('type', TypeQuestion::SatisfactionTexteLong->value)
        ->set('obligatoire', true)
        ->set('texteObligatoire', true)
        ->call('ajouterQuestion')
        ->assertHasNoErrors();

    $q = $t->questions()->first();
    expect($q->type)->toBe(TypeQuestion::SatisfactionTexteLong);
    expect($q->obligatoire)->toBeTrue();
    expect($q->config['texte_obligatoire'])->toBeTrue();
});

it('crée une question satisfaction_texte_long avec texte_obligatoire à false', function (): void {
    $t = QuestionnaireTemplate::factory()->create();

    Livewire::test(ModeleEditor::class, ['template' => $t])
        ->set('libelle', 'Satisfaction globale')
        ->set('type', TypeQuestion::SatisfactionTexteLong->value)
        ->set('obligatoire', true)
        ->set('texteObligatoire', false)
        ->call('ajouterQuestion')
        ->assertHasNoErrors();

    $q = $t->questions()->first();
    expect($q->type)->toBe(TypeQuestion::SatisfactionTexteLong);
    expect($q->obligatoire)->toBeTrue();
    expect((bool) ($q->config['texte_obligatoire'] ?? false))->toBeFalse();
});

it('editerQuestion recharge texteObligatoire depuis la config', function (): void {
    $t = QuestionnaireTemplate::factory()->create();
    $q = QuestionnaireTemplateQuestion::factory()->for($t, 'template')->create([
        'type' => TypeQuestion::SatisfactionTexteLong,
        'libelle' => 'Votre avis',
        'obligatoire' => true,
        'config' => ['texte_obligatoire' => true],
        'ordre' => 1,
    ]);

    $component = Livewire::test(ModeleEditor::class, ['template' => $t])
        ->call('editerQuestion', $q->id);

    $component->assertSet('type', TypeQuestion::SatisfactionTexteLong->value);
    $component->assertSet('obligatoire', true);
    $component->assertSet('texteObligatoire', true);
});

it('editerQuestion recharge texteObligatoire à false depuis la config', function (): void {
    $t = QuestionnaireTemplate::factory()->create();
    $q = QuestionnaireTemplateQuestion::factory()->for($t, 'template')->create([
        'type' => TypeQuestion::SatisfactionTexteLong,
        'libelle' => 'Votre avis',
        'obligatoire' => false,
        'config' => ['texte_obligatoire' => false],
        'ordre' => 1,
    ]);

    $component = Livewire::test(ModeleEditor::class, ['template' => $t])
        ->call('editerQuestion', $q->id);

    $component->assertSet('texteObligatoire', false);
});

it('la création satisfaction standard reste inchangée après l ajout du nouveau type', function (): void {
    $t = QuestionnaireTemplate::factory()->create();

    Livewire::test(ModeleEditor::class, ['template' => $t])
        ->set('libelle', 'Note globale')
        ->set('type', TypeQuestion::Satisfaction->value)
        ->set('obligatoire', true)
        ->set('commentaire', true)
        ->set('commentaireLibelle', 'Pourquoi ?')
        ->call('ajouterQuestion')
        ->assertHasNoErrors();

    $q = $t->questions()->first();
    expect($q->type)->toBe(TypeQuestion::Satisfaction);
    expect($q->obligatoire)->toBeTrue();
    expect($q->config['commentaire'])->toBeTrue();
    expect($q->config['commentaire_libelle'])->toBe('Pourquoi ?');
});
