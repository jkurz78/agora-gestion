<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrigineANouveau;
use App\Enums\StatutANouveau;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class ANouveauGeneration extends TenantModel
{
    protected $table = 'a_nouveau_generations';

    protected $fillable = [
        'association_id',
        'exercice_source',
        'exercice_cible',
        'transaction_id',
        'origine',
        'statut',
        'cree_par_id',
        'invalidee_par_id',
        'invalidee_at',
        'motif_invalidation',
    ];

    protected function casts(): array
    {
        return [
            'association_id' => 'integer',
            'exercice_source' => 'integer',
            'exercice_cible' => 'integer',
            'transaction_id' => 'integer',
            'origine' => OrigineANouveau::class,
            'statut' => StatutANouveau::class,
            'cree_par_id' => 'integer',
            'invalidee_par_id' => 'integer',
            'invalidee_at' => 'datetime',
        ];
    }

    public static function activePourCible(int $annee): ?self
    {
        return self::query()
            ->where('exercice_cible', $annee)
            ->where('statut', StatutANouveau::Active)
            ->latest('id')
            ->first();
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function origines(): HasMany
    {
        return $this->hasMany(ANouveauLigneOrigine::class, 'generation_id');
    }

    public function creePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cree_par_id');
    }

    public function invalideePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invalidee_par_id');
    }
}
