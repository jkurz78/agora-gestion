<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class MessageTemplate extends Model
{
    protected $fillable = [
        'association_id',
        'categorie',
        'nom',
        'objet',
        'corps',
        'type_operation_id',
    ];

    protected function casts(): array
    {
        return [
            'type_operation_id' => 'integer',
        ];
    }

    public function typeOperation(): BelongsTo
    {
        return $this->belongsTo(TypeOperation::class);
    }
}
