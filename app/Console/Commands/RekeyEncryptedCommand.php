<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Re-chiffre toutes les colonnes castées "encrypted" avec la clé staging,
 * à partir de données chiffrées avec la clé prod fournie via PROD_APP_KEY.
 *
 * Utilisé par clone-prod.sh --no-anonymize pour cloner la prod sans toucher
 * aux valeurs, tout en gardant des APP_KEY distinctes entre prod et staging.
 */
final class RekeyEncryptedCommand extends Command
{
    protected $signature = 'staging:rekey-encrypted';

    protected $description = 'Re-chiffre les champs encrypted depuis PROD_APP_KEY vers la clé staging';

    /**
     * Table => list of encrypted columns.
     * Doit rester aligné avec les casts 'encrypted' / 'encrypted:array' des modèles.
     *
     * @var array<string, list<string>>
     */
    private const TABLES = [
        'presences' => ['statut', 'kine', 'commentaire'],
        'smtp_parametres' => ['smtp_password'],
        'participant_donnees_medicales' => [
            'date_naissance', 'sexe', 'poids', 'taille', 'notes',
            'medecin_nom', 'medecin_prenom', 'medecin_telephone',
            'medecin_email', 'medecin_adresse', 'medecin_code_postal', 'medecin_ville',
            'therapeute_nom', 'therapeute_prenom', 'therapeute_telephone',
            'therapeute_email', 'therapeute_adresse', 'therapeute_code_postal', 'therapeute_ville',
        ],
        'users' => ['two_factor_secret', 'two_factor_recovery_codes'],
        'helloasso_parametres' => ['client_secret', 'callback_token'],
        'incoming_mail_parametres' => ['imap_password'],
        'association' => ['anthropic_api_key'],
    ];

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('Cette commande ne peut pas être exécutée en production.');

            return self::FAILURE;
        }

        $prodKeyEnv = env('PROD_APP_KEY');
        if (! is_string($prodKeyEnv) || $prodKeyEnv === '') {
            $this->error('PROD_APP_KEY non fournie (à passer en variable d\'environnement).');

            return self::FAILURE;
        }

        $prodKey = base64_decode(str_replace('base64:', '', $prodKeyEnv), true);
        if ($prodKey === false) {
            $this->error('PROD_APP_KEY invalide (base64 attendu).');

            return self::FAILURE;
        }

        $stagingKeyEnv = (string) config('app.key');
        $stagingKey = base64_decode(str_replace('base64:', '', $stagingKeyEnv), true);

        if ($prodKey === $stagingKey) {
            $this->info('Clés prod et staging identiques — rien à re-chiffrer.');

            return self::SUCCESS;
        }

        $prodEncrypter = new Encrypter($prodKey, (string) config('app.cipher'));

        $totalRows = 0;
        $totalFields = 0;
        $totalSkipped = 0;

        foreach (self::TABLES as $table => $columns) {
            if (! Schema::hasTable($table)) {
                $this->warn("  Table {$table} inexistante — ignorée.");

                continue;
            }

            $existing = array_values(array_filter(
                $columns,
                static fn (string $c): bool => Schema::hasColumn($table, $c),
            ));

            if ($existing === []) {
                continue;
            }

            $rows = DB::table($table)->select(array_merge(['id'], $existing))->get();
            if ($rows->isEmpty()) {
                continue;
            }

            $this->line("  {$table} : {$rows->count()} ligne(s), ".count($existing).' colonne(s)');

            $tableFields = 0;
            $tableSkipped = 0;

            DB::transaction(function () use ($table, $existing, $rows, $prodEncrypter, &$tableFields, &$tableSkipped, &$totalRows): void {
                foreach ($rows as $row) {
                    $updates = [];
                    foreach ($existing as $col) {
                        $value = $row->{$col} ?? null;
                        if ($value === null || $value === '') {
                            continue;
                        }
                        try {
                            $plain = $prodEncrypter->decryptString((string) $value);
                            $updates[$col] = Crypt::encryptString($plain);
                            $tableFields++;
                        } catch (DecryptException) {
                            $tableSkipped++;
                        }
                    }
                    if ($updates !== []) {
                        DB::table($table)->where('id', $row->id)->update($updates);
                        $totalRows++;
                    }
                }
            });

            $this->line("    → {$tableFields} champ(s) re-chiffré(s)".($tableSkipped > 0 ? ", {$tableSkipped} skipped (déjà en clé staging ?)" : ''));

            $totalFields += $tableFields;
            $totalSkipped += $tableSkipped;
        }

        $this->newLine();
        $this->info(sprintf(
            'Rekey terminé : %d champ(s) re-chiffré(s) sur %d ligne(s)%s.',
            $totalFields,
            $totalRows,
            $totalSkipped > 0 ? ", {$totalSkipped} skipped" : '',
        ));

        return self::SUCCESS;
    }
}
