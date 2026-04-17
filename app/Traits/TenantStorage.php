<?php

declare(strict_types=1);

namespace App\Traits;

use InvalidArgumentException;

trait TenantStorage
{
    public function storagePath(string $suffix): string
    {
        if (str_contains($suffix, '..')) {
            throw new InvalidArgumentException('Path traversal interdit.');
        }
        if ($this->association_id === null) {
            throw new InvalidArgumentException('association_id manquant sur '.static::class);
        }
        return 'associations/'.$this->association_id.'/'.ltrim($suffix, '/');
    }
}
