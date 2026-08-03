<?php

declare(strict_types=1);

use App\Enums\JournalComptable;
use App\Models\Association;
use App\Models\Transaction;
use App\Services\TransactionUniverselleService;
use App\Tenant\TenantContext;

beforeEach(function () {
    $this->association = Association::factory()->create();
    TenantContext::boot($this->association);
});

it('exclut les transactions du journal de banque de la liste opérationnelle', function () {
    $assoId = (int) TenantContext::currentId();
    $vente = Transaction::factory()->asRecette()->create(['association_id' => $assoId, 'journal' => JournalComptable::Vente]);
    $banque = Transaction::factory()->asRecette()->create(['association_id' => $assoId, 'journal' => JournalComptable::Banque]);

    $page = app(TransactionUniverselleService::class)->paginate(
        null, null, ['recette'], null, null, null, null, null, null, null, null
    );
    $ids = collect($page['paginator']->items())->pluck('id')->map(fn ($v) => (int) $v)->all();

    expect($ids)->toContain((int) $vente->id);
    expect($ids)->not->toContain((int) $banque->id);
});

it('exclut les transactions du journal de banque (dépense) de la liste opérationnelle', function () {
    $assoId = (int) TenantContext::currentId();
    $achat = Transaction::factory()->asDepense()->create(['association_id' => $assoId, 'journal' => JournalComptable::Achat]);
    $banque = Transaction::factory()->asDepense()->create(['association_id' => $assoId, 'journal' => JournalComptable::Banque]);

    $page = app(TransactionUniverselleService::class)->paginate(
        null, null, ['depense'], null, null, null, null, null, null, null, null
    );
    $ids = collect($page['paginator']->items())->pluck('id')->map(fn ($v) => (int) $v)->all();

    expect($ids)->toContain((int) $achat->id);
    expect($ids)->not->toContain((int) $banque->id);
});
