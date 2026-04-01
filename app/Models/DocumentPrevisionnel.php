<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TypeDocumentPrevisionnel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class DocumentPrevisionnel extends Model
{
    protected $table = 'documents_previsionnels';

    protected $fillable = [
        'operation_id',
        'participant_id',
        'type',
        'numero',
        'version',
        'date',
        'montant_total',
        'lignes_json',
        'pdf_path',
        'saisi_par',
        'exercice',
    ];

    protected function casts(): array
    {
        return [
            'type' => TypeDocumentPrevisionnel::class,
            'date' => 'date',
            'montant_total' => 'decimal:2',
            'lignes_json' => 'array',
            'version' => 'integer',
            'exercice' => 'integer',
            'operation_id' => 'integer',
            'participant_id' => 'integer',
            'saisi_par' => 'integer',
        ];
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(Operation::class);
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(Participant::class);
    }

    public function saisiPar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'saisi_par');
    }
}
