<?php

declare(strict_types=1);

use App\Enums\TypeActionExercice;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exercice_actions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('exercice_id')->constrained('exercices')->cascadeOnDelete();
            $table->string('action', 20);
            $table->foreignId('user_id')->constrained('users');
            $table->text('commentaire')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        $this->seedCreationActions();
    }

    public function down(): void
    {
        Schema::dropIfExists('exercice_actions');
    }

    private function seedCreationActions(): void
    {
        $exercices = DB::table('exercices')->get();
        $adminId = DB::table('users')->value('id') ?? 1;

        foreach ($exercices as $exercice) {
            DB::table('exercice_actions')->insert([
                'exercice_id' => $exercice->id,
                'action' => TypeActionExercice::Creation->value,
                'user_id' => $adminId,
                'commentaire' => 'Création automatique lors de la migration initiale',
                'created_at' => now(),
            ]);
        }
    }
};
