<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TypeQuestion;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class QuestionnaireTemplateQuestion extends TenantModel
{
    use HasFactory;

    protected $fillable = [
        'association_id', 'template_id', 'libelle', 'aide', 'type', 'ordre', 'obligatoire', 'config',
        'grouper_avec_precedente',
    ];

    protected function casts(): array
    {
        return [
            'type' => TypeQuestion::class,
            'ordre' => 'integer',
            'obligatoire' => 'boolean',
            'grouper_avec_precedente' => 'boolean',
            'config' => 'array',
        ];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(QuestionnaireTemplate::class, 'template_id');
    }

    /** @return array<int, array{libelle: string, valeur: string, ordre: int}> */
    public function options(): array
    {
        return $this->config['options'] ?? [];
    }

    public function aDesOptions(): bool
    {
        return $this->type->aDesOptions();
    }
}
