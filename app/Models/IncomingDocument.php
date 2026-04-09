<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class IncomingDocument extends Model
{
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

    /**
     * Chemin (relatif au disk 'local') de la vignette JPEG associée à un storage_path PDF.
     * Convention : le PDF `incoming-documents/{uuid}.pdf` a sa vignette à `incoming-documents/thumbs/{uuid}.jpg`.
     */
    public static function thumbnailPath(string $storagePath): string
    {
        return 'incoming-documents/thumbs/'.pathinfo($storagePath, PATHINFO_FILENAME).'.jpg';
    }
}
