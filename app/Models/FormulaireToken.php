<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class FormulaireToken extends Model
{
    protected $table = 'formulaire_tokens';

    protected $fillable = [
        'participant_id',
        'token',
        'expire_at',
        'rempli_at',
        'rempli_ip',
    ];

    protected function casts(): array
    {
        return [
            'participant_id' => 'integer',
            'expire_at' => 'date',
            'rempli_at' => 'datetime',
        ];
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(Participant::class);
    }

    public function isExpire(): bool
    {
        return $this->expire_at !== null && $this->expire_at->lt(today());
    }

    public function isUtilise(): bool
    {
        return $this->rempli_at !== null;
    }

    public function isValide(): bool
    {
        return $this->expire_at !== null && ! $this->isExpire() && ! $this->isUtilise();
    }
}
