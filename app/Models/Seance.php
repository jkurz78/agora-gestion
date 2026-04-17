<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\TenantStorage;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Seance extends TenantModel
{
    use TenantStorage;

    protected $fillable = [
        'association_id',
        'operation_id',
        'numero',
        'date',
        'titre',
        'feuille_signee_path',
        'feuille_signee_at',
        'feuille_signee_source',
        'feuille_signee_sender_email',
    ];

    protected function casts(): array
    {
        return [
            'operation_id' => 'integer',
            'date' => 'date',
            'numero' => 'integer',
            'feuille_signee_at' => 'datetime',
        ];
    }

    /**
     * Titre affiché : titre propre, sinon fallback depuis TypeOperationSeance, sinon null.
     */
    public function getTitreAfficheAttribute(): ?string
    {
        if ($this->titre !== null && $this->titre !== '') {
            return $this->titre;
        }

        return $this->operation?->typeOperation?->seanceDefaults
            ?->firstWhere('numero', $this->numero)?->titre;
    }

    public function hasSignedSheet(): bool
    {
        return $this->feuille_signee_path !== null;
    }

    public function feuilleSigneeFullPath(): ?string
    {
        return $this->feuille_signee_path !== null
            ? $this->storagePath('seances/'.$this->id.'/feuille-signee.pdf')
            : null;
    }

    public function presencesLocked(): bool
    {
        return $this->hasSignedSheet();
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(Operation::class);
    }

    public function presences(): HasMany
    {
        return $this->hasMany(Presence::class);
    }

    public function reglements(): HasMany
    {
        return $this->hasMany(Reglement::class);
    }
}
