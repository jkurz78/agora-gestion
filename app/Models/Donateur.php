<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Donateur extends Model
{
    use HasFactory;

    protected $table = 'donateurs';

    protected $fillable = [
        'nom',
        'prenom',
        'email',
        'adresse',
    ];

    public function dons(): HasMany
    {
        return $this->hasMany(Don::class);
    }
}
