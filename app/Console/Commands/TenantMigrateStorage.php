<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Association;
use App\Models\DocumentPrevisionnel;
use App\Models\IncomingDocument;
use App\Models\ParticipantDocument;
use App\Models\Provision;
use App\Models\Seance;
use App\Models\Transaction;
use App\Models\TypeOperation;
use App\Tenant\TenantContext;
use Illuminate\Console\Command;

/**
 * Commande idempotente de migration physique des fichiers legacy vers le layout
 * multi-tenant associations/{id}/.
 *
 * Modes :
 *   php artisan tenant:migrate-storage                  → dry-run (liste sans modifier)
 *   php artisan tenant:migrate-storage --force          → déplace + vérifie hash
 *   php artisan tenant:migrate-storage --force --reverse → restaure l'ancien emplacement
 *
 * Option --association={id} pour limiter à un tenant.
 *
 * Cette commande n'appelle pas TenantContext::boot() pour calculer les chemins :
 * elle lit la DB directement (chaque modèle porte son association_id) et itère
 * par association pour le reporting. TenantContext est uniquement booté en interne
 * afin que les scopes Eloquent des modèles TenantModel fonctionnent correctement
 * lors des requêtes DB dans migrateForAssociation().
 *
 * Les migrations Laravel de backfill (Tasks 4-11) ont déjà mis à jour la DB.
 * Cette commande est le complément physique : elle déplace les fichiers depuis
 * storage/app/public/... (ancien disk "public" supprimé en Task 13) vers
 * storage/app/private/associations/{id}/... (nouveau layout).
 */
final class TenantMigrateStorage extends Command
{
    protected $signature = 'tenant:migrate-storage
        {--association= : ID de l\'association à migrer (sinon toutes)}
        {--force : Exécute réellement les déplacements (sinon dry-run)}
        {--reverse : Annule une migration précédente (déplace dans l\'autre sens)}';

    protected $description = 'Migre les fichiers physiques vers le layout associations/{id}/ (dry-run par défaut)';

    public function handle(): int
    {
        $associationIds = $this->option('association')
            ? [(int) $this->option('association')]
            : Association::pluck('id')->all();

        $force = (bool) $this->option('force');
        $reverse = (bool) $this->option('reverse');

        $stats = ['moved' => 0, 'skipped' => 0, 'collisions' => 0, 'missing' => 0];

        foreach ($associationIds as $aid) {
            /** @var Association|null $association */
            $association = Association::find($aid);
            if ($association === null) {
                $this->warn("Association {$aid} introuvable, skip.");

                continue;
            }

            // Boot le TenantContext pour que les scopes Eloquent TenantModel fonctionnent
            TenantContext::boot($association);
            try {
                $this->migrateForAssociation($association, $force, $reverse, $stats);
            } finally {
                TenantContext::clear();
            }
        }

        $mode = $force ? ($reverse ? 'REVERSE' : 'FORCE') : 'DRY-RUN';
        $this->info(sprintf(
            '[%s] moved=%d skipped=%d collisions=%d missing=%d',
            $mode,
            $stats['moved'],
            $stats['skipped'],
            $stats['collisions'],
            $stats['missing'],
        ));

        return self::SUCCESS;
    }

    private function migrateForAssociation(Association $association, bool $force, bool $reverse, array &$stats): void
    {
        $aid = $association->id;

        // ─── 1. Logo Association ────────────────────────────────────────────────
        // Legacy : storage/app/public/association/logo.png
        // Nouveau : storage/app/private/associations/{aid}/branding/logo.png
        if ($association->logo_path !== null) {
            $filename = basename($association->logo_path);
            $old = 'public/association/'.$filename;
            $new = 'private/associations/'.$aid.'/branding/'.$filename;
            $this->moveFile($old, $new, $force, $reverse, $stats);
        }

        // ─── 2. Cachet/signature Association ────────────────────────────────────
        // Legacy : storage/app/public/association/cachet.png
        // Nouveau : storage/app/private/associations/{aid}/branding/cachet.png
        if ($association->cachet_signature_path !== null) {
            $filename = basename($association->cachet_signature_path);
            $old = 'public/association/'.$filename;
            $new = 'private/associations/'.$aid.'/branding/'.$filename;
            $this->moveFile($old, $new, $force, $reverse, $stats);
        }

        // ─── 3. TypeOperation logos et attestations ─────────────────────────────
        // Legacy logo  : storage/app/public/type-operations/{tid}/logo.png
        // Nouveau logo : storage/app/private/associations/{aid}/type-operations/{tid}/logo.png
        TypeOperation::where('association_id', $aid)->each(function (TypeOperation $to) use ($aid, $force, $reverse, &$stats) {
            $tid = $to->id;

            if ($to->logo_path !== null) {
                $filename = basename($to->logo_path);
                $old = "public/type-operations/{$tid}/{$filename}";
                $new = "private/associations/{$aid}/type-operations/{$tid}/{$filename}";
                $this->moveFile($old, $new, $force, $reverse, $stats);
            }

            if ($to->attestation_medicale_path !== null) {
                $filename = basename($to->attestation_medicale_path);
                $old = "public/type-operations/{$tid}/{$filename}";
                $new = "private/associations/{$aid}/type-operations/{$tid}/{$filename}";
                $this->moveFile($old, $new, $force, $reverse, $stats);
            }
        });

        // ─── 4. ParticipantDocument ─────────────────────────────────────────────
        // Legacy : storage/app/private/participants/{pid}/{fname}
        // Nouveau : storage/app/private/associations/{aid}/participants/{pid}/{fname}
        ParticipantDocument::where('association_id', $aid)->each(function (ParticipantDocument $doc) use ($aid, $force, $reverse, &$stats) {
            if ($doc->storage_path === null) {
                return;
            }
            $pid = $doc->participant_id;
            $filename = basename($doc->storage_path);
            $old = "private/participants/{$pid}/{$filename}";
            $new = "private/associations/{$aid}/participants/{$pid}/{$filename}";
            $this->moveFile($old, $new, $force, $reverse, $stats);
        });

        // ─── 5. IncomingDocument ────────────────────────────────────────────────
        // Legacy : storage/app/private/incoming-documents/{uuid}.pdf
        // Nouveau : storage/app/private/associations/{aid}/incoming-documents/{uuid}.pdf
        IncomingDocument::where('association_id', $aid)->each(function (IncomingDocument $doc) use ($aid, $force, $reverse, &$stats) {
            if ($doc->storage_path === null) {
                return;
            }
            $filename = basename($doc->storage_path);
            $old = "private/incoming-documents/{$filename}";
            $new = "private/associations/{$aid}/incoming-documents/{$filename}";
            $this->moveFile($old, $new, $force, $reverse, $stats);
        });

        // ─── 6. Seance feuille signée ───────────────────────────────────────────
        // Legacy : storage/app/private/seances/{sid}/feuille-signee.pdf
        // Nouveau : storage/app/private/associations/{aid}/seances/{sid}/feuille-signee.pdf
        Seance::where('association_id', $aid)
            ->whereNotNull('feuille_signee_path')
            ->each(function (Seance $seance) use ($aid, $force, $reverse, &$stats) {
                $sid = $seance->id;
                $old = "private/seances/{$sid}/feuille-signee.pdf";
                $new = "private/associations/{$aid}/seances/{$sid}/feuille-signee.pdf";
                $this->moveFile($old, $new, $force, $reverse, $stats);
            });

        // ─── 7. Transaction pièce jointe ────────────────────────────────────────
        // Legacy : storage/app/private/pieces-jointes/{tid}/{fname}
        // Nouveau : storage/app/private/associations/{aid}/transactions/{tid}/{fname}
        Transaction::withTrashed()->where('association_id', $aid)
            ->whereNotNull('piece_jointe_path')
            ->each(function (Transaction $tx) use ($aid, $force, $reverse, &$stats) {
                $tid = $tx->id;
                $filename = basename($tx->piece_jointe_path);
                $old = "private/pieces-jointes/{$tid}/{$filename}";
                $new = "private/associations/{$aid}/transactions/{$tid}/{$filename}";
                $this->moveFile($old, $new, $force, $reverse, $stats);
            });

        // ─── 8. DocumentPrevisionnel PDF ────────────────────────────────────────
        // Legacy : storage/app/private/documents-previsionnels/{id}.pdf
        // Nouveau : storage/app/private/associations/{aid}/documents-previsionnels/{id}.pdf
        DocumentPrevisionnel::where('association_id', $aid)
            ->whereNotNull('pdf_path')
            ->each(function (DocumentPrevisionnel $doc) use ($aid, $force, $reverse, &$stats) {
                $filename = basename($doc->pdf_path);
                $old = "private/documents-previsionnels/{$filename}";
                $new = "private/associations/{$aid}/documents-previsionnels/{$filename}";
                $this->moveFile($old, $new, $force, $reverse, $stats);
            });

        // ─── 9. Provision pièce jointe ──────────────────────────────────────────
        // Legacy : storage/app/private/provisions/{pid}/{fname}
        // Nouveau : storage/app/private/associations/{aid}/provisions/{pid}/{fname}
        Provision::withTrashed()->where('association_id', $aid)
            ->whereNotNull('piece_jointe_path')
            ->each(function (Provision $provision) use ($aid, $force, $reverse, &$stats) {
                $pid = $provision->id;
                $filename = basename($provision->piece_jointe_path);
                $old = "private/provisions/{$pid}/{$filename}";
                $new = "private/associations/{$aid}/provisions/{$pid}/{$filename}";
                $this->moveFile($old, $new, $force, $reverse, $stats);
            });
    }

    /**
     * Déplace (ou simule le déplacement de) un fichier.
     *
     * En mode normal ($reverse = false) : $old → $new
     * En mode reverse ($reverse = true) : $new → $old (restauration)
     *
     * Tous les chemins sont relatifs depuis storage_path('app/').
     * Ex: "public/association/logo.png" → storage_path('app/public/association/logo.png')
     *
     * Collision (fichier destination déjà présent) : log + skip, code retour 0 (succès partiel).
     * Fichier source absent : log + skip (idempotence).
     */
    private function moveFile(string $old, string $new, bool $force, bool $reverse, array &$stats): void
    {
        // En mode reverse, on inverse source et destination
        $src = $reverse ? $new : $old;
        $dst = $reverse ? $old : $new;

        $fullSrc = storage_path('app/'.$src);
        $fullDst = storage_path('app/'.$dst);

        // Source absente → skip (idempotence / déjà migré)
        if (! is_file($fullSrc)) {
            $stats['missing']++;
            $this->line("SKIP (missing): {$src}");

            return;
        }

        // Destination déjà présente → collision, ne pas écraser
        if (is_file($fullDst)) {
            $stats['collisions']++;
            $this->line("SKIP (collision): destination {$dst} exists");

            return;
        }

        if (! $force) {
            $this->line("DRY-RUN: {$src} -> {$dst}");

            return;
        }

        // Créer le répertoire de destination si nécessaire
        $dstDir = dirname($fullDst);
        if (! is_dir($dstDir) && ! @mkdir($dstDir, 0775, true) && ! is_dir($dstDir)) {
            $this->error("FAIL mkdir: {$dstDir}");

            return;
        }

        // Copier avec vérification d'intégrité par hash MD5
        $srcHash = md5_file($fullSrc);
        if ($srcHash === false) {
            $this->error("FAIL md5_file source: {$src}");

            return;
        }

        if (! @copy($fullSrc, $fullDst)) {
            $this->error("FAIL copy: {$src} -> {$dst}");

            return;
        }

        $dstHash = md5_file($fullDst);
        if ($dstHash === false || $srcHash !== $dstHash) {
            @unlink($fullDst);
            $this->error("FAIL hash mismatch: {$src} (src={$srcHash} dst={$dstHash})");

            return;
        }

        // Supprimer la source
        if (! @unlink($fullSrc)) {
            $this->warn("WARN delete source failed: {$src} (destination ok)");
            // On compte quand même comme moved car la copie est valide
        }

        $stats['moved']++;
        $this->line("MOVED: {$src} -> {$dst}");
    }
}
