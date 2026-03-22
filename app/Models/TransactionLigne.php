<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

final class TransactionLigne extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'transaction_lignes';

    public $timestamps = false;

    protected $fillable = [
        'transaction_id',
        'sous_categorie_id',
        'operation_id',
        'seance',
        'montant',
        'notes',
        'helloasso_item_id',
        'exercice',
    ];

    protected function casts(): array
    {
        return [
            'montant' => 'decimal:2',
            'transaction_id' => 'integer',
            'sous_categorie_id' => 'integer',
            'operation_id' => 'integer',
            'seance' => 'integer',
            'helloasso_item_id' => 'integer',
            'exercice' => 'integer',
        ];
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function sousCategorie(): BelongsTo
    {
        return $this->belongsTo(SousCategorie::class);
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(Operation::class);
    }

    public function affectations(): HasMany
    {
        return $this->hasMany(TransactionLigneAffectation::class);
    }
}
