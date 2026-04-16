<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TypeTransaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

final class Provision extends TenantModel
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'association_id',
        'exercice',
        'type',
        'sous_categorie_id',
        'libelle',
        'montant',
        'tiers_id',
        'operation_id',
        'seance',
        'date',
        'notes',
        'piece_jointe_path',
        'piece_jointe_nom',
        'piece_jointe_mime',
        'saisi_par',
    ];

    protected function casts(): array
    {
        return [
            'type' => TypeTransaction::class,
            'date' => 'date',
            'montant' => 'decimal:2',
            'exercice' => 'integer',
            'sous_categorie_id' => 'integer',
            'tiers_id' => 'integer',
            'operation_id' => 'integer',
            'seance' => 'integer',
            'saisi_par' => 'integer',
        ];
    }

    public function sousCategorie(): BelongsTo
    {
        return $this->belongsTo(SousCategorie::class);
    }

    public function tiers(): BelongsTo
    {
        return $this->belongsTo(Tiers::class);
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(Operation::class);
    }

    public function saisiPar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'saisi_par');
    }

    /**
     * Impact on the P&L result.
     * depense → +montant (adds to charges)
     * recette → montant as-is (negative for PCA = reduces revenue)
     */
    public function montantSigne(): float
    {
        $montant = (float) $this->montant;

        return $this->type === TypeTransaction::Depense ? abs($montant) : $montant;
    }

    /**
     * @param  Builder<Provision>  $query
     */
    public function scopeForExercice(Builder $query, int $exercice): Builder
    {
        return $query->where('exercice', $exercice);
    }

    public function hasPieceJointe(): bool
    {
        return $this->piece_jointe_path !== null;
    }
}
