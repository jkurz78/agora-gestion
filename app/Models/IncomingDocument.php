<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\TenantStorage;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class IncomingDocument extends TenantModel
{
    use TenantStorage;

    protected $fillable = [
        'association_id',
        'storage_path',
        'original_filename',
        'sender_email',
        'recipient_email',
        'subject',
        'received_at',
        'source_message_id',
        'file_hash',
        'handler_attempted',
        'reason',
        'reason_detail',
    ];

    protected function casts(): array
    {
        return [
            'received_at' => 'datetime',
        ];
    }

    public function association(): BelongsTo
    {
        return $this->belongsTo(Association::class);
    }

    public function incomingFullPath(): string
    {
        return $this->storagePath('incoming-documents/'.basename($this->storage_path));
    }
}
