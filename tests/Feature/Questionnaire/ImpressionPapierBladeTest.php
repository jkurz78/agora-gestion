<?php

declare(strict_types=1);

use App\Enums\TypeQuestion;
use App\Models\Operation;
use App\Models\Participant;
use App\Models\QuestionnaireCampaign;
use App\Models\QuestionnaireCampaignQuestion;
use App\Models\QuestionnaireInvitation;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Construit un ensemble de données minimal pour le template PDF.
 *
 * @param  list<array<string, mixed>>  $questionDefs  tableau de définitions par question
 * @param  list<list<int>>  $groupLayout  liste de groupes, chacun = liste d'index dans $questionDefs
 * @return array{campagne: QuestionnaireCampaign, groupes: array, pages: array}
 */
function buildPaperData(
    array $questionDefs,
    array $groupLayout,
    bool $anonymise = true,
): array {
    $op = Operation::factory()->create();
    $campagne = QuestionnaireCampaign::factory()->for($op, 'operation')->create([
        'titre_affiche' => 'Enquête satisfaction 2026',
        'intro' => 'Merci de répondre à ce questionnaire.',
        'anonymise' => $anonymise,
    ]);

    // Créer les questions
    $questions = [];
    foreach ($questionDefs as $idx => $def) {
        $questions[$idx] = QuestionnaireCampaignQuestion::factory()
            ->for($campagne, 'campaign')
            ->create(array_merge(['ordre' => $idx + 1], $def));
    }

    // Construire les groupes selon le layout fourni
    $groupes = [];
    foreach ($groupLayout as $groupIdx => $questionIndexes) {
        $groupes[$groupIdx] = new Collection(
            array_map(fn (int $qi) => $questions[$qi], $questionIndexes)
        );
    }

    // Créer un participant + une invitation
    $participant = Participant::factory()->create(['operation_id' => $op->id]);
    $invitation = QuestionnaireInvitation::factory()
        ->for($campagne, 'campaign')
        ->create([
            'participant_id' => $participant->id,
            'code_court' => 'ABCD1234',
        ]);

    $pages = [
        [
            'invitation' => $invitation->load('participant.tiers'),
            'qr' => 'data:image/png;base64,AAAA',
        ],
    ];

    return compact('campagne', 'groupes', 'pages');
}

// -----------------------------------------------------------------------
// Tests
// -----------------------------------------------------------------------

it('rend le nom du participant et le code_court dans la page', function (): void {
    $data = buildPaperData(
        [['libelle' => 'Êtes-vous satisfait ?', 'type' => TypeQuestion::Satisfaction]],
        [[0]],
    );

    $html = view('pdf.questionnaire-papier', [
        'campagne' => $data['campagne'],
        'nomAsso' => 'Mon Association',
        'logoDataUri' => null,
        'groupes' => $data['groupes'],
        'pages' => $data['pages'],
    ])->render();

    $invitation = $data['pages'][0]['invitation'];
    $nomParticipant = $invitation->participant->tiers->displayName();

    expect($html)
        ->toContain($nomParticipant)
        ->toContain('ABCD1234');
});

it('intègre le QR comme balise img data-URI', function (): void {
    $data = buildPaperData(
        [['libelle' => 'Question simple', 'type' => TypeQuestion::TexteCourt]],
        [[0]],
    );

    $html = view('pdf.questionnaire-papier', [
        'campagne' => $data['campagne'],
        'nomAsso' => 'Mon Association',
        'logoDataUri' => null,
        'groupes' => $data['groupes'],
        'pages' => $data['pages'],
    ])->render();

    expect($html)->toContain('src="data:image/png;base64');
});

it('rend le libellé de chaque question', function (): void {
    $data = buildPaperData(
        [
            ['libelle' => 'Question A texte court', 'type' => TypeQuestion::TexteCourt],
            ['libelle' => 'Question B texte long', 'type' => TypeQuestion::TexteLong],
        ],
        [[0], [1]],
    );

    $html = view('pdf.questionnaire-papier', [
        'campagne' => $data['campagne'],
        'nomAsso' => 'Mon Association',
        'logoDataUri' => null,
        'groupes' => $data['groupes'],
        'pages' => $data['pages'],
    ])->render();

    expect($html)
        ->toContain('Question A texte court')
        ->toContain('Question B texte long');
});

it('rend une question Information en intertitre sans zone de réponse (pas de filet)', function (): void {
    $data = buildPaperData(
        [
            ['libelle' => 'Section introduction', 'type' => TypeQuestion::Information, 'aide' => 'Texte d\'aide info'],
        ],
        [[0]],
    );

    $html = view('pdf.questionnaire-papier', [
        'campagne' => $data['campagne'],
        'nomAsso' => 'Mon Association',
        'logoDataUri' => null,
        'groupes' => $data['groupes'],
        'pages' => $data['pages'],
    ])->render();

    // Libellé présent en class info-titre
    expect($html)->toContain('info-titre');
    expect($html)->toContain('Section introduction');

    // Pas de border-bottom (zone de réponse texte_court) pour ce type
    // On vérifie l'absence du div "ruled line" en comptant les occurrences de border-bottom:1px solid #333; height:1.6em
    $countRuledLines = substr_count($html, 'border-bottom:1px solid #333; height:1.6em');
    expect($countRuledLines)->toBe(0);
});

it('groupe deux questions dans le même bloc groupe-papier', function (): void {
    // 3 questions : [0,1] dans un groupe, [2] dans un autre
    $data = buildPaperData(
        [
            ['libelle' => 'Q1 grouped', 'type' => TypeQuestion::TexteCourt],
            ['libelle' => 'Q2 grouped', 'type' => TypeQuestion::TexteCourt],
            ['libelle' => 'Q3 separate', 'type' => TypeQuestion::TexteCourt],
        ],
        [[0, 1], [2]], // 2 groupes
    );

    $html = view('pdf.questionnaire-papier', [
        'campagne' => $data['campagne'],
        'nomAsso' => 'Mon Association',
        'logoDataUri' => null,
        'groupes' => $data['groupes'],
        'pages' => $data['pages'],
    ])->render();

    // Exactement 2 blocs groupe-papier (= 2 groupes passés)
    // On cherche 'class="groupe-papier"' pour ne pas compter la définition CSS
    $count = substr_count($html, 'class="groupe-papier"');
    expect($count)->toBe(2);

    // Les deux questions groupées sont bien dans l'HTML (libellés présents)
    expect($html)
        ->toContain('Q1 grouped')
        ->toContain('Q2 grouped')
        ->toContain('Q3 separate');
});

it('insère la classe coupe (page-break) uniquement à partir de la 2e invitation', function (): void {
    $op = Operation::factory()->create();
    $campagne = QuestionnaireCampaign::factory()->for($op, 'operation')->create([
        'titre_affiche' => 'Test multi-pages',
        'anonymise' => false,
    ]);

    QuestionnaireCampaignQuestion::factory()
        ->for($campagne, 'campaign')
        ->create(['libelle' => 'Question multi', 'type' => TypeQuestion::TexteCourt, 'ordre' => 1]);

    $participants = Participant::factory()->count(2)->create(['operation_id' => $op->id]);

    $pages = $participants->map(function (Participant $p) use ($campagne) {
        $inv = QuestionnaireInvitation::factory()
            ->for($campagne, 'campaign')
            ->create(['participant_id' => $p->id, 'code_court' => strtoupper(Str::random(8))]);

        return [
            'invitation' => $inv->load('participant.tiers'),
            'qr' => 'data:image/png;base64,AAAA',
        ];
    })->values()->all();

    $groupes = [new Collection($campagne->questions->all())];

    $html = view('pdf.questionnaire-papier', [
        'campagne' => $campagne,
        'nomAsso' => 'Mon Association',
        'logoDataUri' => null,
        'groupes' => $groupes,
        'pages' => $pages,
    ])->render();

    // La classe coupe (page-break-before) doit apparaître exactement 1 fois (la 2e invitation)
    // On cherche ' coupe' (avec un espace) dans les balises de div pour ne pas compter le CSS
    $countCoupe = substr_count($html, 'invitation coupe');
    expect($countCoupe)->toBe(1);
});

it('affiche le bloc consentement quand anonymise=true et pas quand false', function (): void {
    $questionDef = [['libelle' => 'Ma question', 'type' => TypeQuestion::TexteCourt]];
    $groupLayout = [[0]];

    $viewData = fn (bool $anon) => function () use ($questionDef, $groupLayout, $anon): string {
        $d = buildPaperData($questionDef, $groupLayout, $anon);

        return view('pdf.questionnaire-papier', [
            'campagne' => $d['campagne'],
            'nomAsso' => 'Asso Test',
            'logoDataUri' => null,
            'groupes' => $d['groupes'],
            'pages' => $d['pages'],
        ])->render();
    };

    $htmlAnon = $viewData(true)();
    $htmlNonAnon = $viewData(false)();

    // Présent quand anonymise=true
    expect($htmlAnon)->toContain("J'accepte d'être recontacté(e)");

    // Absent quand anonymise=false
    expect($htmlNonAnon)->not->toContain("J'accepte d'être recontacté(e)");
});

it('rend le remerciement en fin d\'invitation', function (): void {
    $data = buildPaperData(
        [['libelle' => 'Une question', 'type' => TypeQuestion::TexteCourt]],
        [[0]],
    );

    $html = view('pdf.questionnaire-papier', [
        'campagne' => $data['campagne'],
        'nomAsso' => 'Mon Association',
        'logoDataUri' => null,
        'groupes' => $data['groupes'],
        'pages' => $data['pages'],
    ])->render();

    expect($html)->toContain('Merci pour votre retour');
});

it('rend le logo quand logoDataUri est fourni', function (): void {
    $data = buildPaperData(
        [['libelle' => 'Question logo', 'type' => TypeQuestion::TexteCourt]],
        [[0]],
    );

    $logoUri = 'data:image/png;base64,iVBORw0KGgo=';

    $html = view('pdf.questionnaire-papier', [
        'campagne' => $data['campagne'],
        'nomAsso' => 'Mon Association',
        'logoDataUri' => $logoUri,
        'groupes' => $data['groupes'],
        'pages' => $data['pages'],
    ])->render();

    expect($html)->toContain('src="'.$logoUri.'"');
});

it('rend les 5 niveaux de satisfaction avec des cases à cocher', function (): void {
    $data = buildPaperData(
        [['libelle' => 'Satisfaction test', 'type' => TypeQuestion::Satisfaction]],
        [[0]],
    );

    $html = view('pdf.questionnaire-papier', [
        'campagne' => $data['campagne'],
        'nomAsso' => 'Mon Association',
        'logoDataUri' => null,
        'groupes' => $data['groupes'],
        'pages' => $data['pages'],
    ])->render();

    // Les 5 niveaux
    expect($html)
        ->toContain('Très insatisfait')
        ->toContain('Neutre')
        ->toContain('Très satisfait');
});

it('rend les labels gauche/droite d\'une question ressenti', function (): void {
    $data = buildPaperData(
        [[
            'libelle' => 'Mon ressenti',
            'type' => TypeQuestion::Ressenti,
            'config' => ['label_gauche' => 'Jamais', 'label_droite' => 'Toujours'],
        ]],
        [[0]],
    );

    $html = view('pdf.questionnaire-papier', [
        'campagne' => $data['campagne'],
        'nomAsso' => 'Mon Association',
        'logoDataUri' => null,
        'groupes' => $data['groupes'],
        'pages' => $data['pages'],
    ])->render();

    expect($html)
        ->toContain('Jamais')
        ->toContain('Toujours')
        ->toContain('Marquez d\'une croix');
});

it('rend les options d\'un choix unique', function (): void {
    $data = buildPaperData(
        [[
            'libelle' => 'Votre choix',
            'type' => TypeQuestion::ChoixUnique,
            'config' => ['options' => [
                ['libelle' => 'Option Alpha', 'valeur' => 'alpha', 'ordre' => 1],
                ['libelle' => 'Option Beta', 'valeur' => 'beta', 'ordre' => 2],
            ]],
        ]],
        [[0]],
    );

    $html = view('pdf.questionnaire-papier', [
        'campagne' => $data['campagne'],
        'nomAsso' => 'Mon Association',
        'logoDataUri' => null,
        'groupes' => $data['groupes'],
        'pages' => $data['pages'],
    ])->render();

    expect($html)
        ->toContain('Option Alpha')
        ->toContain('Option Beta');
});

it('utilise les labels par défaut pour ressenti sans config', function (): void {
    $data = buildPaperData(
        [['libelle' => 'Ressenti sans config', 'type' => TypeQuestion::Ressenti]],
        [[0]],
    );

    $html = view('pdf.questionnaire-papier', [
        'campagne' => $data['campagne'],
        'nomAsso' => 'Mon Association',
        'logoDataUri' => null,
        'groupes' => $data['groupes'],
        'pages' => $data['pages'],
    ])->render();

    expect($html)
        ->toContain('Pas du tout')
        ->toContain('Tout à fait');
});
