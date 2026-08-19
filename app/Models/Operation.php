<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\StatutOperation;
use App\Services\ExerciceService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

final class Operation extends TenantModel
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'association_id',
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

    /**
     * Opérations proposables dans un sélecteur de saisie (dépense, recette,
     * ventilation, note de frais, facture, prompt OCR).
     *
     * Seul le statut compte. Les dates de l'opération sont volontairement
     * ignorées : une transaction porte sa propre date, et c'est elle — non la
     * période de l'opération — qui détermine son exercice comptable. Une
     * opération pluriannuelle, ancienne ou administrativement datée sur une
     * autre période reste donc imputable.
     *
     * Ce scope filtre des PROPOSITIONS, il n'est pas une garde métier : une
     * écriture qui hérite son opération d'un lien préexistant (synchronisation
     * HelloAsso, règlement d'un participant) s'enregistre sans condition de
     * statut. Ne pas le réutiliser côté TransactionService.
     *
     * L'isolation tenant vient du scope global de TenantModel.
     *
     * @param  Builder<Operation>  $query
     */
    public function scopeProposableALaSaisie(Builder $query): Builder
    {
        return $query->where('statut', StatutOperation::EnCours);
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

    public function questionnaireCampaigns(): HasMany
    {
        return $this->hasMany(QuestionnaireCampaign::class);
    }
}
