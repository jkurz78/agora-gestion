<?php

declare(strict_types=1);

use App\Enums\CategorieEmail;
use App\Models\Association;
use App\Models\EmailLog;
use App\Models\EmailOpen;
use App\Models\Participant;
use App\Models\Tiers;
use App\Services\Tiers\DTO\CommunicationsTimelineDTO;
use App\Services\Tiers\DTO\EmailLogLigneDTO;
use App\Services\Tiers\TiersCommunicationsTimelineService;
use App\Tenant\TenantContext;

beforeEach(function (): void {
    $this->association = Association::factory()->create();
    TenantContext::boot($this->association);
    $this->service = app(TiersCommunicationsTimelineService::class);
});
