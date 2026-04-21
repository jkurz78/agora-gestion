<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\StatutNoteDeFrais;
use App\Enums\StatutReglement;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

final class NoteDeFrais extends TenantModel
{
    use HasFactory, SoftDeletes;

    protected $table = 'notes_de_frais';

    protected $fillable = [
        'association_id',
        'tiers_id',
        'date',
        'libelle',
        'statut',
        'motif_rejet',
        'transaction_id',
        'don_transaction_id',
        'abandon_creance_propose',
        'submitted_at',
        'validee_at',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'submitted_at' => 'datetime',
            'validee_at' => 'datetime',
            'archived_at' => 'datetime',
            'abandon_creance_propose' => 'boolean',
        ];
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /**
     * Accessor for statut: returns Payee when the linked transaction is Recu or Pointe
     * (payment received or bank-reconciled = effectively paid), otherwise casts from DB value.
     */
    public function getStatutAttribute(mixed $value): StatutNoteDeFrais
    {
        if ($value === 'validee'
            && in_array(
                $this->transaction?->statut_reglement,
                [StatutReglement::Recu, StatutReglement::Pointe],
                true
            )
        ) {
            return StatutNoteDeFrais::Payee;
        }

        return StatutNoteDeFrais::from((string) $value);
    }

    public function tiers(): BelongsTo
    {
        return $this->belongsTo(Tiers::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function donTransaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'don_transaction_id');
    }

    public function lignes(): HasMany
    {
        return $this->hasMany(NoteDeFraisLigne::class);
    }
}
