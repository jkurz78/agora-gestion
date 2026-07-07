<?php

declare(strict_types=1);

use App\Enums\TypeQuestion;
use App\Models\QuestionnaireTemplate;
use App\Models\QuestionnaireTemplateQuestion;
use App\Tenant\TenantContext;

it('crée un modèle avec questions ordonnées et scopé tenant', function (): void {
    $template = QuestionnaireTemplate::factory()->create(['titre_interne' => 'Satisfaction fin parcours']);

    QuestionnaireTemplateQuestion::factory()->for($template, 'template')->create([
        'libelle' => 'Globalement satisfait ?',
        'type' => TypeQuestion::Satisfaction,
        'ordre' => 1,
    ]);
    QuestionnaireTemplateQuestion::factory()->for($template, 'template')->create([
        'libelle' => 'Un commentaire ?',
        'type' => TypeQuestion::TexteLong,
        'ordre' => 2,
    ]);

    $fresh = $template->fresh('questions');
    expect($fresh->questions)->toHaveCount(2);
    expect($fresh->questions->first()->type)->toBe(TypeQuestion::Satisfaction);
    expect($fresh->association_id)->toBe((int) TenantContext::currentId());
});
