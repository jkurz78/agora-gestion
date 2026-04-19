<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private function indexExists(): bool
    {
        $indexes = Schema::getIndexes('tiers');

        foreach ($indexes as $index) {
            $cols = $index['columns'];

            if (
                in_array('association_id', $cols, true)
                && in_array('email', $cols, true)
                && count($cols) === 2
            ) {
                return true;
            }
        }

        return false;
    }

    public function up(): void
    {
        // Idempotent: only create the index if it does not already exist.
        if (! $this->indexExists()) {
            Schema::table('tiers', function (Blueprint $table) {
                $table->index(['association_id', 'email']);
            });
        }
    }

    public function down(): void
    {
        if ($this->indexExists()) {
            Schema::table('tiers', function (Blueprint $table) {
                $table->dropIndex(['association_id', 'email']);
            });
        }
    }
};
