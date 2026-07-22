<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ANouveauLigneOrigine extends TenantModel
{
    protected $table = 'a_nouveau_ligne_origines';

    protected $fillable = [
        'association_id',
        'generation_id',
        'ligne_an_id',
        'ligne_source_id',
        'ligne_racine_id',
    ];

    protected function casts(): array
    {
        return [
            'association_id' => 'integer',
            'generation_id' => 'integer',
            'ligne_an_id' => 'integer',
            'ligne_source_id' => 'integer',
            'ligne_racine_id' => 'integer',
        ];
    }

    public function generation(): BelongsTo
    {
        return $this->belongsTo(ANouveauGeneration::class, 'generation_id');
    }

    public function ligneAN(): BelongsTo
    {
        return $this->belongsTo(TransactionLigne::class, 'ligne_an_id');
    }

    public function ligneSource(): BelongsTo
    {
        return $this->belongsTo(TransactionLigne::class, 'ligne_source_id');
    }

    public function ligneRacine(): BelongsTo
    {
        return $this->belongsTo(TransactionLigne::class, 'ligne_racine_id');
    }
}
