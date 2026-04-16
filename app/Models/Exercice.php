<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\StatutExercice;
use App\Services\ExerciceService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Exercice extends TenantModel
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
        return app(ExerciceService::class)->label($this->annee);
    }

    public function dateDebut(): Carbon
    {
        $range = app(ExerciceService::class)->dateRange($this->annee);

        return Carbon::instance($range['start']);
    }

    public function dateFin(): Carbon
    {
        $range = app(ExerciceService::class)->dateRange($this->annee);

        return Carbon::instance($range['end']);
    }
}
