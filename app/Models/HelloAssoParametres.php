<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\HelloAssoEnvironnement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class HelloAssoParametres extends Model
{
    protected $table = 'helloasso_parametres';

    protected $fillable = [
        'association_id',
        'client_id',
        'client_secret',
        'organisation_slug',
        'environnement',
    ];

    protected function casts(): array
    {
        return [
            'client_secret' => 'encrypted',
            'association_id' => 'integer',
            'environnement' => HelloAssoEnvironnement::class,
        ];
    }

    public function association(): BelongsTo
    {
        return $this->belongsTo(Association::class);
    }
}
