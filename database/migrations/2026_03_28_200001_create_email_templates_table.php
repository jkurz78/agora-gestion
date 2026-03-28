<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_templates', function (Blueprint $table) {
            $table->id();
            $table->string('categorie', 20);
            $table->foreignId('type_operation_id')->nullable()->constrained('type_operations')->cascadeOnDelete();
            $table->string('objet', 255);
            $table->text('corps');
            $table->timestamps();

            $table->unique(['categorie', 'type_operation_id']);
        });

        // Migrate existing email templates from type_operations
        $types = DB::table('type_operations')
            ->whereNotNull('email_formulaire_corps')
            ->get(['id', 'email_formulaire_objet', 'email_formulaire_corps']);

        foreach ($types as $type) {
            DB::table('email_templates')->insert([
                'categorie' => 'formulaire',
                'type_operation_id' => $type->id,
                'objet' => $type->email_formulaire_objet ?? 'Formulaire à compléter — {operation}',
                'corps' => $type->email_formulaire_corps,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Remove old columns
        Schema::table('type_operations', function (Blueprint $table) {
            $table->dropColumn(['email_formulaire_objet', 'email_formulaire_corps']);
        });
    }

    public function down(): void
    {
        Schema::table('type_operations', function (Blueprint $table) {
            $table->string('email_formulaire_objet', 255)->nullable();
            $table->text('email_formulaire_corps')->nullable();
        });

        $templates = DB::table('email_templates')
            ->where('categorie', 'formulaire')
            ->whereNotNull('type_operation_id')
            ->get();

        foreach ($templates as $t) {
            DB::table('type_operations')
                ->where('id', $t->type_operation_id)
                ->update([
                    'email_formulaire_objet' => $t->objet,
                    'email_formulaire_corps' => $t->corps,
                ]);
        }

        Schema::dropIfExists('email_templates');
    }
};
