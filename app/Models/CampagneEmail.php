<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class CampagneEmail extends Model
{
    protected $table = 'campagnes_email';

    protected $fillable = [
        'operation_id',
        'objet',
        'corps',
        'nb_destinataires',
        'nb_erreurs',
        'envoye_par',
    ];

    protected function casts(): array
    {
        return [
            'operation_id' => 'integer',
            'nb_destinataires' => 'integer',
            'nb_erreurs' => 'integer',
            'envoye_par' => 'integer',
        ];
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(Operation::class);
    }

    public function envoyePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'envoye_par');
    }

    public function emailLogs(): HasMany
    {
        return $this->hasMany(EmailLog::class, 'campagne_id');
    }
}
