<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\HelloAssoEnvironnement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class HelloAssoParametres extends Model
{
    protected $table = 'helloasso_parametres';

    protected $hidden = [
        'client_secret',
        'callback_token',
    ];

    protected $fillable = [
        'association_id',
        'client_id',
        'client_secret',
        'organisation_slug',
        'environnement',
        'callback_token',
        'compte_helloasso_id',
        'compte_versement_id',
        'sous_categorie_don_id',
        'sous_categorie_cotisation_id',
        'sous_categorie_inscription_id',
    ];

    protected function casts(): array
    {
        return [
            'client_secret' => 'encrypted',
            'callback_token' => 'encrypted',
            'association_id' => 'integer',
            'environnement' => HelloAssoEnvironnement::class,
            'compte_helloasso_id' => 'integer',
            'compte_versement_id' => 'integer',
            'sous_categorie_don_id' => 'integer',
            'sous_categorie_cotisation_id' => 'integer',
            'sous_categorie_inscription_id' => 'integer',
        ];
    }

    public function association(): BelongsTo
    {
        return $this->belongsTo(Association::class);
    }

    public function compteHelloasso(): BelongsTo
    {
        return $this->belongsTo(CompteBancaire::class, 'compte_helloasso_id');
    }

    public function compteVersement(): BelongsTo
    {
        return $this->belongsTo(CompteBancaire::class, 'compte_versement_id');
    }

    public function formMappings(): HasMany
    {
        return $this->hasMany(HelloAssoFormMapping::class, 'helloasso_parametres_id');
    }
}
