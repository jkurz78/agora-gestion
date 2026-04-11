<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class EmailLog extends Model
{
    protected $fillable = [
        'tiers_id',
        'participant_id',
        'operation_id',
        'categorie',
        'email_template_id',
        'destinataire_email',
        'destinataire_nom',
        'objet',
        'statut',
        'erreur_message',
        'envoye_par',
        'campagne_id',
    ];

    public function tiers(): BelongsTo
    {
        return $this->belongsTo(Tiers::class);
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(Participant::class);
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(Operation::class);
    }

    public function emailTemplate(): BelongsTo
    {
        return $this->belongsTo(EmailTemplate::class);
    }

    public function envoyePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'envoye_par');
    }

    public function campagne(): BelongsTo
    {
        return $this->belongsTo(CampagneEmail::class, 'campagne_id');
    }
}
