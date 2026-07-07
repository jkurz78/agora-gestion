<?php

declare(strict_types=1);

use App\Models\Operation;
use App\Models\QuestionnaireCampaign;

it('relie une opération à ses campagnes', function (): void {
    $op = Operation::factory()->create();
    QuestionnaireCampaign::factory()->for($op, 'operation')->count(2)->create();

    expect($op->fresh()->questionnaireCampaigns)->toHaveCount(2);
});
