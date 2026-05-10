<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class HelloAssoFormMapping extends Model
{
    protected $table = 'helloasso_form_mappings';

    protected $fillable = [
        'helloasso_parametres_id',
        'form_slug',
        'form_type',
        'form_title',
        'start_date',
        'end_date',
        'state',
        'operation_id',
        'ignore',
        'imported_at',
        'sous_categorie_id',
    ];

    protected function casts(): array
    {
        return [
            'helloasso_parametres_id' => 'integer',
            'operation_id' => 'integer',
            'start_date' => 'date',
            'end_date' => 'date',
            'ignore' => 'boolean',
            'imported_at' => 'datetime',
            'sous_categorie_id' => 'integer',
        ];
    }

    public function parametres(): BelongsTo
    {
        return $this->belongsTo(HelloAssoParametres::class, 'helloasso_parametres_id');
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(Operation::class);
    }

    public function sousCategorie(): BelongsTo
    {
        return $this->belongsTo(SousCategorie::class);
    }
}
