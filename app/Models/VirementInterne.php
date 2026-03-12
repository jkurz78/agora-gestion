<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

final class VirementInterne extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'virements_internes';

    protected $fillable = [
        'date',
        'montant',
        'compte_source_id',
        'compte_destination_id',
        'reference',
        'notes',
        'saisi_par',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'montant' => 'decimal:2',
        ];
    }

    public function compteSource(): BelongsTo
    {
        return $this->belongsTo(CompteBancaire::class, 'compte_source_id');
    }

    public function compteDestination(): BelongsTo
    {
        return $this->belongsTo(CompteBancaire::class, 'compte_destination_id');
    }

    public function saisiPar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'saisi_par');
    }

    /**
     * @param  Builder<VirementInterne>  $query
     */
    public function scopeForExercice(Builder $query, int $exercice): Builder
    {
        return $query->whereBetween('date', [
            "{$exercice}-09-01",
            ($exercice + 1).'-08-31',
        ]);
    }
}
