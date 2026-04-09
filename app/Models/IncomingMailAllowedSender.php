<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class IncomingMailAllowedSender extends Model
{
    protected $fillable = [
        'association_id',
        'email',
        'label',
    ];

    public function association(): BelongsTo
    {
        return $this->belongsTo(Association::class);
    }
}
