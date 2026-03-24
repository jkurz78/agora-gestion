<?php

declare(strict_types=1);

use App\Enums\StatutExercice;
use App\Services\ExerciceService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exercices', function (Blueprint $table): void {
            $table->id();
            $table->smallInteger('annee')->unique();
            $table->string('statut', 20)->default(StatutExercice::Ouvert->value);
            $table->datetime('date_cloture')->nullable();
            $table->foreignId('cloture_par_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        $this->seedFromTransactions();
    }

    public function down(): void
    {
        Schema::dropIfExists('exercices');
    }

    private function seedFromTransactions(): void
    {
        if (! Schema::hasTable('transactions')) {
            return;
        }

        // YEAR() is a MySQL/MariaDB function not available in SQLite (used for tests)
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $dates = DB::table('transactions')
            ->whereNull('deleted_at')
            ->selectRaw('DISTINCT YEAR(date) as y, MONTH(date) as m')
            ->get();

        $annees = collect();
        foreach ($dates as $row) {
            $annee = $row->m >= 9 ? $row->y : $row->y - 1;
            $annees->push($annee);
        }

        // Also include virements
        $virementDates = DB::table('virements_internes')
            ->whereNull('deleted_at')
            ->selectRaw('DISTINCT YEAR(date) as y, MONTH(date) as m')
            ->get();

        foreach ($virementDates as $row) {
            $annee = $row->m >= 9 ? $row->y : $row->y - 1;
            $annees->push($annee);
        }

        $annees = $annees->unique()->sort();

        // Add current exercice if not already present
        $exerciceService = app(ExerciceService::class);
        $currentAnnee = $exerciceService->current();
        $annees->push($currentAnnee);
        $annees = $annees->unique()->sort();

        foreach ($annees as $annee) {
            DB::table('exercices')->insert([
                'annee' => $annee,
                'statut' => StatutExercice::Ouvert->value,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
};
