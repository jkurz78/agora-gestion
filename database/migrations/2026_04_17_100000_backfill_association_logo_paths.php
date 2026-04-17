<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('association')->get()->each(function (stdClass $row): void {
            $updates = [];

            if ($row->logo_path !== null) {
                $old = $row->logo_path;
                $shortName = basename($old);
                $fullNew = storage_path('app/private/associations/'.$row->id.'/branding/'.$shortName);

                // Move the physical file if it still lives under the old public path
                if (str_contains($old, '/') || str_contains($old, 'association')) {
                    $fullOld = storage_path('app/public/'.$old);
                    if (file_exists($fullOld) && ! file_exists($fullNew)) {
                        if (! is_dir(dirname($fullNew))) {
                            mkdir(dirname($fullNew), 0775, true);
                        }
                        rename($fullOld, $fullNew);
                    }
                }

                $updates['logo_path'] = $shortName;
            }

            if ($row->cachet_signature_path !== null) {
                $old = $row->cachet_signature_path;
                $shortName = basename($old);
                $fullNew = storage_path('app/private/associations/'.$row->id.'/branding/'.$shortName);

                if (str_contains($old, '/') || str_contains($old, 'association')) {
                    $fullOld = storage_path('app/public/'.$old);
                    if (file_exists($fullOld) && ! file_exists($fullNew)) {
                        if (! is_dir(dirname($fullNew))) {
                            mkdir(dirname($fullNew), 0775, true);
                        }
                        rename($fullOld, $fullNew);
                    }
                }

                $updates['cachet_signature_path'] = $shortName;
            }

            if (! empty($updates)) {
                DB::table('association')->where('id', $row->id)->update($updates);
            }
        });
    }

    public function down(): void
    {
        // Reversing this migration would require knowing the original path prefix per row.
        // Not implemented — restore from backup if needed.
    }
};
