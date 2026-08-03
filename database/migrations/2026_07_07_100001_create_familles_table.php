<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * DC-1 du programme « dissolution sous_categories → comptes ».
 *
 * `familles` nomme un préfixe à 2 chiffres (classes 6/7 du PCG) — le
 * rattachement compte → famille reste DÉRIVÉ par préfixe (pas de FK).
 *
 * La donnée existante est migrée localement par cette migration :
 *  1. Chaque `categories.nom` au format "NN - Libellé" devient une famille
 *     (code=NN, nom=Libellé trim). Deux catégories parsant le même code pour
 *     la même association → la première gagne (unique par association+code).
 *  2. Chaque préfixe distinct des comptes de classe 6/7 sans famille encore
 *     nommée reçoit une famille de secours (nom = code).
 *
 * La logique reste locale afin que le rejeu historique soit autonome.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('familles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('association_id')->constrained('association')->cascadeOnDelete();
            $table->string('code', 2);
            $table->string('nom');
            $table->timestamps();

            $table->unique(['association_id', 'code']);
        });

        $this->seedDepuisCategories();
        $this->seedDepuisComptesOrphelins();
    }

    public function down(): void
    {
        Schema::dropIfExists('familles');
    }

    private function seedDepuisCategories(): void
    {
        $vusDansCePasse = [];

        foreach (DB::table('categories')->select(['association_id', 'nom'])->get() as $categorie) {
            if (! preg_match('/^(\d{2})\s*-\s*(.+)$/u', (string) $categorie->nom, $matches)) {
                continue;
            }

            $associationId = (int) $categorie->association_id;
            $code = $matches[1];
            $cle = $associationId.':'.$code;

            if (isset($vusDansCePasse[$cle])) {
                continue;
            }

            $vusDansCePasse[$cle] = true;
            $this->inserer($associationId, $code, trim($matches[2]));
        }
    }

    private function seedDepuisComptesOrphelins(): void
    {
        $prefixesParAssociation = [];

        foreach (DB::table('comptes')
            ->select(['association_id', 'numero_pcg'])
            ->whereIn('classe', [6, 7])
            ->get() as $compte) {
            $associationId = (int) $compte->association_id;
            $code = Str::substr((string) $compte->numero_pcg, 0, 2);
            $prefixesParAssociation[$associationId][$code] = true;
        }

        foreach ($prefixesParAssociation as $associationId => $codes) {
            foreach (array_keys($codes) as $code) {
                $code = (string) $code;
                $this->inserer($associationId, $code, $code);
            }
        }
    }

    private function inserer(int $associationId, string $code, string $nom): void
    {
        $insertClause = DB::getDriverName() === 'sqlite' ? 'INSERT OR IGNORE' : 'INSERT IGNORE';
        $safeCode = str_replace("'", "''", $code);
        $safeNom = str_replace("'", "''", $nom);

        DB::statement(<<<SQL
            {$insertClause} INTO familles (association_id, code, nom, created_at, updated_at)
            VALUES ({$associationId}, '{$safeCode}', '{$safeNom}', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
            SQL);
    }
};
