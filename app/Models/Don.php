<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ModePaiement;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

final class Don extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'dons';

    protected $fillable = [
        'donateur_id',
        'date',
        'montant',
        'mode_paiement',
        'objet',
        'operation_id',
        'seance',
        'compte_id',
        'pointe',
        'recu_emis',
        'saisi_par',
        'rapprochement_id',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'montant' => 'decimal:2',
            'mode_paiement' => ModePaiement::class,
            'pointe' => 'boolean',
            'recu_emis' => 'boolean',
        ];
    }

    public function donateur(): BelongsTo
    {
        return $this->belongsTo(Donateur::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'saisi_par');
    }

    public function compte(): BelongsTo
    {
        return $this->belongsTo(CompteBancaire::class, 'compte_id');
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(Operation::class);
    }

    public function rapprochement(): BelongsTo
    {
        return $this->belongsTo(RapprochementBancaire::class, 'rapprochement_id');
    }

    public function isLockedByRapprochement(): bool
    {
        return $this->rapprochement_id !== null
            && $this->rapprochement?->isVerrouille() === true;
    }

    /**
     * @param  Builder<Don>  $query
     */
    public function scopeForExercice(Builder $query, int $exercice): Builder
    {
        return $query->whereBetween('date', [
            "{$exercice}-09-01",
            ($exercice + 1).'-08-31',
        ]);
    }
}
