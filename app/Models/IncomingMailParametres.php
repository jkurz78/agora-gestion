<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class IncomingMailParametres extends Model
{
    protected $table = 'incoming_mail_parametres';

    protected $fillable = [
        'association_id',
        'enabled',
        'imap_host',
        'imap_port',
        'imap_encryption',
        'imap_username',
        'imap_password',
        'processed_folder',
        'errors_folder',
        'max_per_run',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'imap_port' => 'integer',
            'imap_password' => 'encrypted',
            'max_per_run' => 'integer',
        ];
    }

    public function association(): BelongsTo
    {
        return $this->belongsTo(Association::class);
    }
}
