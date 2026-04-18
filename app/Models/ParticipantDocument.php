<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\TenantStorage;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ParticipantDocument extends TenantModel
{
    use TenantStorage;

    protected $fillable = [
        'association_id',
        'participant_id',
        'label',
        'storage_path',
        'original_filename',
        'source',
    ];

    public function participant(): BelongsTo
    {
        return $this->belongsTo(Participant::class);
    }

    public function documentFullPath(): string
    {
        return $this->storagePath('participants/'.$this->participant_id.'/'.basename($this->storage_path));
    }
}
