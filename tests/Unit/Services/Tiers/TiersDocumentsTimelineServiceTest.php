<?php

declare(strict_types=1);

use App\Enums\TypeTransaction;
use App\Models\Adhesion;
use App\Models\Association;
use App\Models\Facture;
use App\Models\FacturePartenaireDeposee;
use App\Models\Participant;
use App\Models\ParticipantDocument;
use App\Models\RecuFiscalEmis;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Services\Tiers\DTO\DocumentsTimelineDTO;
use App\Services\Tiers\TiersDocumentsTimelineService;
use App\Tenant\TenantContext;

beforeEach(function (): void {
    $this->association = Association::factory()->create();
    TenantContext::boot($this->association);
    $this->service = app(TiersDocumentsTimelineService::class);
});
