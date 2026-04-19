<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

final class NoteDeFraisLigne extends Model
{
    use HasFactory;

    protected $table = 'notes_de_frais_lignes';

    protected static function booted(): void
    {
        self::deleting(function (self $ligne): void {
            if ($ligne->piece_jointe_path && Storage::disk('local')->exists($ligne->piece_jointe_path)) {
                Storage::disk('local')->delete($ligne->piece_jointe_path);
            }
        });
    }

    protected $fillable = [
        'note_de_frais_id',
        'sous_categorie_id',
        'operation_id',
        'seance_id',
        'libelle',
        'montant',
        'piece_jointe_path',
    ];

    protected function casts(): array
    {
        return [
            'montant' => 'decimal:2',
        ];
    }

    public function noteDeFrais(): BelongsTo
    {
        return $this->belongsTo(NoteDeFrais::class);
    }

    public function sousCategorie(): BelongsTo
    {
        return $this->belongsTo(SousCategorie::class);
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(Operation::class);
    }

    public function seance(): BelongsTo
    {
        return $this->belongsTo(Seance::class);
    }
}
