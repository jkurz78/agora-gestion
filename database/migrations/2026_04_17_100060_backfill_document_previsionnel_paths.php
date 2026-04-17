<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('documents_previsionnels')
            ->whereNotNull('pdf_path')
            ->orderBy('id')
            ->chunkById(100, function ($rows): void {
                foreach ($rows as $row) {
                    $old = $row->pdf_path;

                    if (! str_contains($old, '/')) {
                        continue; // déjà migré
                    }

                    $new = basename($old);

                    $fullOld = storage_path('app/private/'.$old);
                    $fullNew = storage_path(
                        'app/private/associations/'.$row->association_id
                        .'/documents-previsionnels/'.$new
                    );

                    if (is_file($fullOld) && ! is_file($fullNew)) {
                        @mkdir(dirname($fullNew), 0775, true);
                        @rename($fullOld, $fullNew);
                    }

                    if (is_file($fullNew)) {
                        DB::table('documents_previsionnels')
                            ->where('id', $row->id)
                            ->update(['pdf_path' => $new]);
                    } else {
                        Log::warning('S2 backfill: expected file missing, DB not updated', [
                            'table' => 'documents_previsionnels', 'id' => $row->id,
                            'old_value' => $old, 'expected_new_path' => $fullNew,
                        ]);
                    }
                }
            });
    }

    public function down(): void
    {
        // Non réversible sans backup — restaurer depuis sauvegarde si nécessaire.
    }
};
