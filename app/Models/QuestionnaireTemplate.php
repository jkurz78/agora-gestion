<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class QuestionnaireTemplate extends TenantModel
{
    use HasFactory;

    protected $fillable = [
        'association_id', 'titre_interne', 'titre_affiche', 'intro', 'remerciement', 'actif',
        'anonymise', 'autoriser_retour', 'afficher_progression',
    ];

    protected function casts(): array
    {
        return [
            'actif' => 'boolean',
            'anonymise' => 'boolean',
            'autoriser_retour' => 'boolean',
            'afficher_progression' => 'boolean',
        ];
    }

    public function questions(): HasMany
    {
        return $this->hasMany(QuestionnaireTemplateQuestion::class, 'template_id')->orderBy('ordre');
    }
}
