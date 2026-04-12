<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class TypeOperationSeance extends Model
{
    protected $fillable = [
        'type_operation_id',
        'numero',
        'titre',
    ];

    protected function casts(): array
    {
        return [
            'type_operation_id' => 'integer',
            'numero' => 'integer',
        ];
    }

    public function typeOperation(): BelongsTo
    {
        return $this->belongsTo(TypeOperation::class);
    }
}
