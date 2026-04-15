<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\StatutExercice;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Exercice extends Model
{
    protected $fillable = [
        'association_id',
        'annee',
        'statut',
        'date_cloture',
        'cloture_par_id',
        'helloasso_url',
    ];

    protected function casts(): array
    {
        return [
            'annee' => 'integer',
            'statut' => StatutExercice::class,
            'date_cloture' => 'datetime',
            'cloture_par_id' => 'integer',
        ];
    }

    public function cloturePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cloture_par_id');
    }

    public function actions(): HasMany
    {
        return $this->hasMany(ExerciceAction::class);
    }

    public function scopeOuvert(Builder $query): Builder
    {
        return $query->where('statut', StatutExercice::Ouvert);
    }

    public function scopeCloture(Builder $query): Builder
    {
        return $query->where('statut', StatutExercice::Cloture);
    }

    public function isCloture(): bool
    {
        return $this->statut === StatutExercice::Cloture;
    }

    public function label(): string
    {
        return $this->annee.'-'.($this->annee + 1);
    }

    public function dateDebut(): Carbon
    {
        return Carbon::create($this->annee, 9, 1)->startOfDay();
    }

    public function dateFin(): Carbon
    {
        return Carbon::create($this->annee + 1, 8, 31)->startOfDay();
    }
}
