<?php

declare(strict_types=1);

use App\Services\Questionnaire\QuestionnaireEcranResolver;

function makeQuestion(bool $grouperAvecPrecedente): object
{
    return new class($grouperAvecPrecedente)
    {
        public function __construct(public readonly bool $grouper_avec_precedente) {}
    };
}

describe('QuestionnaireEcranResolver', function (): void {
    beforeEach(function (): void {
        $this->resolver = new QuestionnaireEcranResolver;
    });

    it('retourne un tableau vide pour une collection vide', function (): void {
        $ecrans = $this->resolver->decouper(collect());

        expect($ecrans)->toBe([]);
    });

    it('crée 3 écrans de 1 quand toutes les questions ont grouper_avec_precedente=false', function (): void {
        $questions = collect([
            makeQuestion(false),
            makeQuestion(false),
            makeQuestion(false),
        ]);

        $ecrans = $this->resolver->decouper($questions);

        expect($ecrans)->toHaveCount(3);
        expect($ecrans[0])->toHaveCount(1);
        expect($ecrans[1])->toHaveCount(1);
        expect($ecrans[2])->toHaveCount(1);
    });

    it('regroupe Q1+Q2+Q3 sur un seul écran quand Q2=true et Q3=true', function (): void {
        // Q2 et Q3 sont toutes les deux "grouper_avec_precedente=true" :
        // elles s'ajoutent à l'écran courant → 1 seul écran [Q1, Q2, Q3].
        $q1 = makeQuestion(false);
        $q2 = makeQuestion(true);
        $q3 = makeQuestion(true);

        $ecrans = $this->resolver->decouper(collect([$q1, $q2, $q3]));

        expect($ecrans)->toHaveCount(1);
        expect($ecrans[0]->all())->toBe([$q1, $q2, $q3]);
    });

    it('crée les bons écrans pour Q1=false, Q2=true, Q3=false, Q4=true', function (): void {
        $q1 = makeQuestion(false);
        $q2 = makeQuestion(true);
        $q3 = makeQuestion(false);
        $q4 = makeQuestion(true);

        $ecrans = $this->resolver->decouper(collect([$q1, $q2, $q3, $q4]));

        expect($ecrans)->toHaveCount(2);
        expect($ecrans[0]->all())->toBe([$q1, $q2]);
        expect($ecrans[1]->all())->toBe([$q3, $q4]);
    });

    it('démarre un écran même si la première question a grouper_avec_precedente=true', function (): void {
        $q1 = makeQuestion(true);

        $ecrans = $this->resolver->decouper(collect([$q1]));

        expect($ecrans)->toHaveCount(1);
        expect($ecrans[0]->all())->toBe([$q1]);
    });
});
