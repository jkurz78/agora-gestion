<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TypeActionExercice;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ExerciceAction extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'exercice_actions';

    protected $fillable = [
        'exercice_id',
        'action',
        'user_id',
        'commentaire',
    ];

    protected function casts(): array
    {
        return [
            'action' => TypeActionExercice::class,
            'exercice_id' => 'integer',
            'user_id' => 'integer',
        ];
    }

    public function exercice(): BelongsTo
    {
        return $this->belongsTo(Exercice::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
