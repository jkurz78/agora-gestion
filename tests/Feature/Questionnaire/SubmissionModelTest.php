<?php

declare(strict_types=1);

use App\Models\QuestionnaireAnswer;
use App\Models\QuestionnaireSubmission;

it('relie une soumission à ses réponses', function (): void {
    $submission = QuestionnaireSubmission::factory()->create();
    QuestionnaireAnswer::factory()->for($submission, 'submission')->count(3)->create();

    expect($submission->fresh()->answers)->toHaveCount(3);
});
