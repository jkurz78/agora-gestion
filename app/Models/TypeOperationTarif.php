<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class TypeOperationTarif extends Model
{
    use HasFactory;

    protected $fillable = [
        'type_operation_id',
        'libelle',
        'montant',
    ];

    protected function casts(): array
    {
        return [
            'montant' => 'decimal:2',
            'type_operation_id' => 'integer',
        ];
    }

    public function typeOperation(): BelongsTo
    {
        return $this->belongsTo(TypeOperation::class);
    }

    public function participants(): HasMany
    {
        return $this->hasMany(Participant::class);
    }
}
