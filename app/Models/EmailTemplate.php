<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CategorieEmail;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class EmailTemplate extends Model
{
    protected $fillable = [
        'categorie',
        'type_operation_id',
        'objet',
        'corps',
    ];

    protected function casts(): array
    {
        return [
            'categorie' => CategorieEmail::class,
            'type_operation_id' => 'integer',
        ];
    }

    public function typeOperation(): BelongsTo
    {
        return $this->belongsTo(TypeOperation::class);
    }

    public static function sanitizeCorps(string $html): string
    {
        return strip_tags($html, '<p><br><strong><em><u><ul><ol><li><a><h1><h2><h3><h4><span><div>');
    }
}
