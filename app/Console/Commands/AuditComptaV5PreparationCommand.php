<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Association;
use App\Tenant\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Audit pré-backfill du moteur partie double (slice 1, Step 2 du plan).
 *
 * Lecture seule : aucune écriture en base. Produit un rapport JSON dans
 * `storage/audits/compta-v5-YYYY-MM-DD-HHMMSS.json` + une synthèse console.
 *
 * Sections :
 *  1. sous_categories sans `code_cerfa` (BLOQUANT — fera échouer la migration Step 3)
 *  2. modes de paiement hors matrice §4.3 ({cheque, especes, virement, cb, prelevement})
 *  3. transactions sans tiers (informational — backfill génère les lignes sans lettrage)
 *  4. extournes anciennes (extournee_at set mais pas de row `extournes`)
 *  5. helloasso payloads inhabituels (helloasso_payment_id set mais tiers_id null
 *     ou mode_paiement vide)
 *
 * Exit code 1 ssi section 1 (bloquante) a au moins une entrée ; sinon 0.
 */
final class AuditComptaV5PreparationCommand extends Command
{
    protected $signature = 'audit:compta-v5-preparation {--asso= : Limiter l\'audit à une association (ID). Sinon audite toutes les associations.}';

    protected $description = 'Audit pré-backfill partie double : sous-catégories sans code_cerfa, modes de paiement non standard, transactions sans tiers, extournes incohérentes, payloads HelloAsso inhabituels.';

    /** Section bloquante : sa présence force exit code 1. */
    private const SECTION_SOUS_CAT_SANS_CODE = 'sous_categories_sans_code_cerfa';

    private const SECTION_MODES_PAIEMENT_INCONNUS = 'modes_paiement_inconnus';

    private const SECTION_TX_SANS_TIERS = 'transactions_sans_tiers';

    private const SECTION_EXTOURNES_INCOHERENTES = 'extournes_incoherentes';

    private const SECTION_HELLOASSO_INHABITUELS = 'helloasso_inhabituels';

    /** Matrice §4.3 — modes de paiement standard couverts par EcritureGenerator. */
    private const MODES_PAIEMENT_STANDARD = ['cheque', 'especes', 'virement', 'cb', 'prelevement'];

    public function handle(): int
    {
        $assoOption = $this->option('asso');
        $associations = $assoOption !== null
            ? Association::query()->whereKey((int) $assoOption)->get()
            : Association::query()->get();

        if ($associations->isEmpty()) {
            $this->warn('Aucune association à auditer.');

            return self::SUCCESS;
        }

        $sections = [
            self::SECTION_SOUS_CAT_SANS_CODE => ['count' => 0, 'items' => []],
            self::SECTION_MODES_PAIEMENT_INCONNUS => ['count' => 0, 'items' => []],
            self::SECTION_TX_SANS_TIERS => ['count' => 0, 'examples' => []],
            self::SECTION_EXTOURNES_INCOHERENTES => ['count' => 0, 'items' => []],
            self::SECTION_HELLOASSO_INHABITUELS => ['count' => 0, 'items' => []],
        ];

        // Sauvegarde du contexte tenant pour le restaurer en fin de commande
        // (pertinent quand la commande est invoquée depuis un test qui a son
        // propre TenantContext booté).
        $previousTenant = TenantContext::current();

        try {
            foreach ($associations as $asso) {
                TenantContext::clear();
                TenantContext::boot($asso);

                $sections[self::SECTION_SOUS_CAT_SANS_CODE] = $this->mergeSection(
                    $sections[self::SECTION_SOUS_CAT_SANS_CODE],
                    $this->auditSousCategoriesSansCodeCerfa((int) $asso->id),
                    'items'
                );
                $sections[self::SECTION_MODES_PAIEMENT_INCONNUS] = $this->mergeSection(
                    $sections[self::SECTION_MODES_PAIEMENT_INCONNUS],
                    $this->auditModesPaiementInconnus((int) $asso->id),
                    'items'
                );
                $sections[self::SECTION_TX_SANS_TIERS] = $this->mergeSection(
                    $sections[self::SECTION_TX_SANS_TIERS],
                    $this->auditTransactionsSansTiers((int) $asso->id),
                    'examples'
                );
                $sections[self::SECTION_EXTOURNES_INCOHERENTES] = $this->mergeSection(
                    $sections[self::SECTION_EXTOURNES_INCOHERENTES],
                    $this->auditExtournesIncoherentes((int) $asso->id),
                    'items'
                );
                $sections[self::SECTION_HELLOASSO_INHABITUELS] = $this->mergeSection(
                    $sections[self::SECTION_HELLOASSO_INHABITUELS],
                    $this->auditHelloAssoInhabituels((int) $asso->id),
                    'items'
                );
            }
        } finally {
            TenantContext::clear();
            if ($previousTenant !== null) {
                TenantContext::boot($previousTenant);
            }
        }

        $report = [
            'generated_at' => now()->toIso8601String(),
            'asso_filter' => $assoOption !== null ? (int) $assoOption : null,
            'sections' => $sections,
        ];

        $this->writeJsonReport($report);
        $this->renderConsoleSummary($sections);

        $blocking = $sections[self::SECTION_SOUS_CAT_SANS_CODE]['count'] > 0;

        return $blocking ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  array{count: int, items?: list<array<string, mixed>>, examples?: list<array<string, mixed>>}  $current
     * @param  array{count: int, items?: list<array<string, mixed>>, examples?: list<array<string, mixed>>}  $new
     * @return array{count: int, items?: list<array<string, mixed>>, examples?: list<array<string, mixed>>}
     */
    private function mergeSection(array $current, array $new, string $listKey): array
    {
        $current['count'] += $new['count'];
        $currentList = $current[$listKey] ?? [];
        $newList = $new[$listKey] ?? [];
        $current[$listKey] = array_values(array_merge($currentList, $newList));

        return $current;
    }

    /**
     * Section 1 — sous-catégories sans code_cerfa (BLOQUANT).
     *
     * @return array{count: int, items: list<array<string, mixed>>}
     */
    private function auditSousCategoriesSansCodeCerfa(int $associationId): array
    {
        $rows = DB::table('sous_categories')
            ->where('association_id', $associationId)
            ->whereNull('code_cerfa')
            ->select('id', 'nom', 'association_id')
            ->orderBy('id')
            ->get();

        $items = $rows->map(fn ($r): array => [
            'id' => (int) $r->id,
            'nom' => (string) $r->nom,
            'association_id' => (int) $r->association_id,
        ])->all();

        return ['count' => count($items), 'items' => $items];
    }

    /**
     * Section 2 — modes de paiement non couverts par la matrice §4.3.
     *
     * @return array{count: int, items: list<array<string, mixed>>}
     */
    private function auditModesPaiementInconnus(int $associationId): array
    {
        $rows = DB::table('transactions')
            ->where('association_id', $associationId)
            ->whereNull('deleted_at')
            ->whereNotNull('mode_paiement')
            ->whereNotIn('mode_paiement', self::MODES_PAIEMENT_STANDARD)
            ->select('mode_paiement', DB::raw('COUNT(*) as count'))
            ->groupBy('mode_paiement')
            ->orderBy('mode_paiement')
            ->get();

        $items = $rows->map(fn ($r): array => [
            'association_id' => $associationId,
            'mode_paiement' => (string) $r->mode_paiement,
            'count' => (int) $r->count,
        ])->all();

        return ['count' => count($items), 'items' => $items];
    }

    /**
     * Section 3 — transactions sans tiers (informational, non bloquant).
     *
     * @return array{count: int, examples: list<array<string, mixed>>}
     */
    private function auditTransactionsSansTiers(int $associationId): array
    {
        $base = DB::table('transactions')
            ->where('association_id', $associationId)
            ->whereNull('deleted_at')
            ->whereNull('tiers_id');

        $count = $base->count();

        $examples = (clone $base)
            ->select('id', 'libelle', 'montant_total', 'date')
            ->orderBy('id')
            ->limit(5)
            ->get()
            ->map(fn ($r): array => [
                'association_id' => $associationId,
                'id' => (int) $r->id,
                'libelle' => (string) ($r->libelle ?? ''),
                'montant' => (float) $r->montant_total,
                'date' => (string) $r->date,
            ])->all();

        return ['count' => $count, 'examples' => $examples];
    }

    /**
     * Section 4 — transactions extournées (extournee_at set) absentes de la table `extournes`.
     *
     * @return array{count: int, items: list<array<string, mixed>>}
     */
    private function auditExtournesIncoherentes(int $associationId): array
    {
        $rows = DB::table('transactions as t')
            ->where('t.association_id', $associationId)
            ->whereNotNull('t.extournee_at')
            ->whereNull('t.deleted_at')
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('extournes as e')
                    ->whereColumn('e.transaction_origine_id', 't.id')
                    ->whereNull('e.deleted_at');
            })
            ->select('t.id', 't.libelle', 't.extournee_at')
            ->orderBy('t.id')
            ->limit(20)
            ->get();

        $items = $rows->map(fn ($r): array => [
            'association_id' => $associationId,
            'id' => (int) $r->id,
            'libelle' => (string) ($r->libelle ?? ''),
            'extournee_at' => (string) $r->extournee_at,
        ])->all();

        return ['count' => count($items), 'items' => $items];
    }

    /**
     * Section 5 — payloads HelloAsso inhabituels (payment_id set mais tiers manquant ou
     * mode de paiement vide).
     *
     * @return array{count: int, items: list<array<string, mixed>>}
     */
    private function auditHelloAssoInhabituels(int $associationId): array
    {
        $base = DB::table('transactions')
            ->where('association_id', $associationId)
            ->whereNull('deleted_at')
            ->whereNotNull('helloasso_payment_id')
            ->where(function ($q) {
                $q->whereNull('tiers_id')
                    ->orWhereNull('mode_paiement')
                    ->orWhere('mode_paiement', '');
            });

        $count = $base->count();

        $items = (clone $base)
            ->select('id', 'libelle', 'mode_paiement', 'tiers_id', 'helloasso_payment_id')
            ->orderBy('id')
            ->limit(20)
            ->get()
            ->map(fn ($r): array => [
                'association_id' => $associationId,
                'id' => (int) $r->id,
                'libelle' => (string) ($r->libelle ?? ''),
                'mode_paiement' => $r->mode_paiement !== null ? (string) $r->mode_paiement : null,
                'tiers_id' => $r->tiers_id !== null ? (int) $r->tiers_id : null,
                'helloasso_payment_id' => (int) $r->helloasso_payment_id,
            ])->all();

        return ['count' => $count, 'items' => $items];
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function writeJsonReport(array $report): void
    {
        $dir = storage_path('audits');
        if (! File::isDirectory($dir)) {
            File::makeDirectory($dir, 0775, true);
        }

        $filename = sprintf('compta-v5-%s.json', now()->format('Y-m-d-His'));
        $path = $dir.DIRECTORY_SEPARATOR.$filename;

        File::put(
            $path,
            json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
        );

        $this->info("Rapport JSON écrit : {$path}");
    }

    /**
     * @param  array<string, array{count: int, items?: list<array<string, mixed>>, examples?: list<array<string, mixed>>}>  $sections
     */
    private function renderConsoleSummary(array $sections): void
    {
        $totalIssues = array_sum(array_map(static fn (array $s): int => $s['count'], $sections));

        $this->table(
            ['Section', 'Issues', 'Bloquant ?'],
            [
                ['Sous-catégories sans code_cerfa', $sections[self::SECTION_SOUS_CAT_SANS_CODE]['count'], 'OUI'],
                ['Modes de paiement inconnus', $sections[self::SECTION_MODES_PAIEMENT_INCONNUS]['count'], 'non'],
                ['Transactions sans tiers', $sections[self::SECTION_TX_SANS_TIERS]['count'], 'non'],
                ['Extournes incohérentes', $sections[self::SECTION_EXTOURNES_INCOHERENTES]['count'], 'non'],
                ['HelloAsso payloads inhabituels', $sections[self::SECTION_HELLOASSO_INHABITUELS]['count'], 'non'],
            ]
        );

        if ($totalIssues === 0) {
            $this->info('Audit terminé : 0 issue détectée.');
        } else {
            $this->warn("Audit terminé : {$totalIssues} issue(s) détectée(s) (voir rapport JSON).");
            if ($sections[self::SECTION_SOUS_CAT_SANS_CODE]['count'] > 0) {
                $this->error('La section bloquante "sous-catégories sans code_cerfa" a des entrées. La migration Step 3 échouera tant que ces lignes ne sont pas corrigées.');
            }
        }
    }
}
