<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\StatutOperation;
use App\Services\ExerciceService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Operation extends Model
{
    use HasFactory;

    protected $fillable = [
        'nom',
        'description',
        'date_debut',
        'date_fin',
        'nombre_seances',
        'statut',
        'type_operation_id',
    ];

    protected function casts(): array
    {
        return [
            'statut' => StatutOperation::class,
            'date_debut' => 'date',
            'date_fin' => 'date',
            'nombre_seances' => 'integer',
            'type_operation_id' => 'integer',
        ];
    }

    /**
     * Filtre les opérations dont les dates chevauchent l'exercice donné.
     * Exercice N : 1er sept N → 31 août N+1.
     *
     * @param  Builder<Operation>  $query
     */
    public function scopeForExercice(Builder $query, int $exercice): Builder
    {
        $range = app(ExerciceService::class)->dateRange($exercice);

        return $query
            ->whereNotNull('date_debut')
            ->whereNotNull('date_fin')
            ->where('date_debut', '<=', $range['end']->toDateString())
            ->where('date_fin', '>=', $range['start']->toDateString());
    }

    public function typeOperation(): BelongsTo
    {
        return $this->belongsTo(TypeOperation::class);
    }

    public function transactionLignes(): HasMany
    {
        return $this->hasMany(TransactionLigne::class);
    }

    public function participants(): HasMany
    {
        return $this->hasMany(Participant::class);
    }

    public function seances(): HasMany
    {
        return $this->hasMany(Seance::class)->orderBy('numero');
    }

    public function emailLogs(): HasMany
    {
        return $this->hasMany(EmailLog::class);
    }
}
