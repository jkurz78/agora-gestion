<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class EncadrementPrevision extends TenantModel
{
    use HasFactory;

    protected $fillable = [
        'operation_id',
        'tiers_id',
        'compte_id',
        'seance_id',
        'montant_prevu',
    ];

    protected function casts(): array
    {
        return [
            'montant_prevu' => 'decimal:2',
            'compte_id' => 'integer',
        ];
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(Operation::class);
    }

    public function tiers(): BelongsTo
    {
        return $this->belongsTo(Tiers::class);
    }

    public function compte(): BelongsTo
    {
        return $this->belongsTo(Compte::class, 'compte_id');
    }

    public function seance(): BelongsTo
    {
        return $this->belongsTo(Seance::class);
    }
}
