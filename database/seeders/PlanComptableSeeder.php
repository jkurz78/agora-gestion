<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Association;
use App\Models\Compte;
use App\Models\Famille;
use App\Models\UsageCompte;
use App\Services\Onboarding\DefaultChartOfAccountsService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

final class PlanComptableSeeder extends Seeder
{
    public function run(): void
    {
        $driver = DB::connection()->getDriverName();
        $disableFk = $driver === 'sqlite' ? 'PRAGMA foreign_keys = OFF' : 'SET FOREIGN_KEY_CHECKS=0';
        $enableFk = $driver === 'sqlite' ? 'PRAGMA foreign_keys = ON' : 'SET FOREIGN_KEY_CHECKS=1';

        DB::statement($disableFk);
        UsageCompte::truncate();
        Compte::truncate();
        Famille::truncate();
        DB::statement($enableFk);

        app(DefaultChartOfAccountsService::class)->applyTo(Association::firstOrFail());
    }
}
