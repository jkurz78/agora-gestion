<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class Association extends Model
{
    protected $table = 'association';

    protected $fillable = [
        'id',
        'nom',
        'adresse',
        'code_postal',
        'ville',
        'email',
        'telephone',
        'logo_path',
    ];

    protected function casts(): array
    {
        return [
            'id'          => 'integer',
            'nom'         => 'string',
            'adresse'     => 'string',
            'code_postal' => 'string',
            'ville'       => 'string',
            'email'       => 'string',
            'telephone'   => 'string',
            'logo_path'   => 'string',
        ];
    }
}
