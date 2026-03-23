<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ModePaiement;
use App\Models\HelloAssoParametres;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

final class HelloAssoSyncService
{
    /** @var array<string, int> Cache formSlug → operation_id */
    private array $formMappingCache = [];

    public function __construct(
        private readonly HelloAssoParametres $parametres,
    ) {
        // Pre-load form mappings
        foreach ($this->parametres->formMappings as $mapping) {
            if ($mapping->operation_id !== null) {
                $this->formMappingCache[$mapping->form_slug] = $mapping->operation_id;
            }
        }
    }

    /**
     * Import HelloAsso orders into SVS transactions.
     *
     * @param  list<array<string, mixed>>  $orders
     */
    public function synchroniser(array $orders, int $exercice): HelloAssoSyncResult
    {
        $txCreated = 0;
        $txUpdated = 0;
        $lignesCreated = 0;
        $lignesUpdated = 0;
        $skipped = 0;
        $errors = [];

        foreach ($orders as $order) {
            try {
                $result = $this->processOrder($order, $exercice);
                $txCreated += $result['tx_created'];
                $txUpdated += $result['tx_updated'];
                $lignesCreated += $result['lignes_created'];
                $lignesUpdated += $result['lignes_updated'];
                $skipped += $result['skipped'];
            } catch (\Throwable $e) {
                $errors[] = "Commande #{$order['id']} : {$e->getMessage()}";
                $skipped++;
            }
        }

        return new HelloAssoSyncResult(
            transactionsCreated: $txCreated,
            transactionsUpdated: $txUpdated,
            lignesCreated: $lignesCreated,
            lignesUpdated: $lignesUpdated,
            ordersSkipped: $skipped,
            errors: $errors,
        );
    }

    /**
     * @return array{tx_created: int, tx_updated: int, lignes_created: int, lignes_updated: int, skipped: int}
     */
    private function processOrder(array $order, int $exercice): array
    {
        // Group items by beneficiary email
        $groups = $this->groupItemsByBeneficiary($order);
        $result = ['tx_created' => 0, 'tx_updated' => 0, 'lignes_created' => 0, 'lignes_updated' => 0, 'skipped' => 0];

        $orderDate = Carbon::parse($order['date'])->toDateString();
        $modePaiement = $this->resolveModePaiement($order['payments'] ?? []);

        foreach ($groups as $email => $items) {
            $tiers = Tiers::where('email', $email)->where('est_helloasso', true)->first();
            if ($tiers === null) {
                throw new \RuntimeException("Tiers non trouvé pour {$email} — rapprochez d'abord les tiers");
            }

            // Pre-validate: resolve sous-catégories and opérations for all items
            $resolvedItems = [];
            foreach ($items as $item) {
                $resolved = $this->resolveItem($item, $order['formSlug']);
                $resolvedItems[] = $resolved;
            }

            DB::transaction(function () use ($order, $orderDate, $modePaiement, $tiers, $resolvedItems, $exercice, &$result) {
                $totalCentimes = collect($resolvedItems)->sum(fn (array $r) => $r['item']['amount']);
                $totalEuros = round($totalCentimes / 100, 2);

                // Upsert Transaction
                $existing = Transaction::where('helloasso_order_id', $order['id'])
                    ->where('tiers_id', $tiers->id)
                    ->first();

                if ($existing) {
                    $existing->update([
                        'date' => $orderDate,
                        'montant_total' => $totalEuros,
                        'mode_paiement' => $modePaiement,
                        'libelle' => $this->buildLibelle($order),
                    ]);
                    $result['tx_updated']++;
                    $tx = $existing;
                } else {
                    $tx = Transaction::create([
                        'type' => 'recette',
                        'date' => $orderDate,
                        'libelle' => $this->buildLibelle($order),
                        'montant_total' => $totalEuros,
                        'mode_paiement' => $modePaiement,
                        'tiers_id' => $tiers->id,
                        'compte_id' => $this->parametres->compte_helloasso_id,
                        'helloasso_order_id' => $order['id'],
                        'saisi_par' => auth()->id(),
                        'numero_piece' => app(NumeroPieceService::class)->assign(Carbon::parse($orderDate)),
                    ]);
                    $result['tx_created']++;
                }

                // Upsert TransactionLignes
                foreach ($resolvedItems as $resolved) {
                    $item = $resolved['item'];
                    $montantEuros = round($item['amount'] / 100, 2);

                    $existingLigne = TransactionLigne::where('helloasso_item_id', $item['id'])->first();

                    if ($existingLigne) {
                        $existingLigne->update([
                            'transaction_id' => $tx->id,
                            'sous_categorie_id' => $resolved['sous_categorie_id'],
                            'operation_id' => $resolved['operation_id'],
                            'montant' => $montantEuros,
                            'exercice' => $resolved['exercice'] === 'use_sync_exercice' ? $exercice : null,
                        ]);
                        $result['lignes_updated']++;
                    } else {
                        TransactionLigne::create([
                            'transaction_id' => $tx->id,
                            'sous_categorie_id' => $resolved['sous_categorie_id'],
                            'operation_id' => $resolved['operation_id'],
                            'montant' => $montantEuros,
                            'helloasso_item_id' => $item['id'],
                            'exercice' => $resolved['exercice'] === 'use_sync_exercice' ? $exercice : null,
                        ]);
                        $result['lignes_created']++;
                    }
                }
            });
        }

        return $result;
    }

    /**
     * Group items by beneficiary email. Uses 'user' if present, else 'payer'.
     *
     * @return array<string, list<array>>
     */
    private function groupItemsByBeneficiary(array $order): array
    {
        $groups = [];

        foreach ($order['items'] as $item) {
            // Per-item user takes priority, fallback to order-level user/payer
            $person = $item['user'] ?? $order['user'] ?? $order['payer'] ?? null;
            if ($person === null) {
                continue;
            }

            $email = strtolower(trim($person['email'] ?? ''));
            if ($email === '') {
                continue;
            }

            $groups[$email][] = $item;
        }

        return $groups;
    }

    /**
     * Resolve sous-catégorie and opération for an item.
     *
     * @return array{item: array, sous_categorie_id: int, operation_id: ?int, exercice: string|null}
     */
    private function resolveItem(array $item, string $formSlug): array
    {
        $type = $item['type'] ?? 'Donation';
        $sousCategorieId = $this->resolveSousCategorie($type);

        $operationId = $this->formMappingCache[$formSlug] ?? null;

        // Registration items require an operation
        if ($type === 'Registration' && $operationId === null) {
            throw new \RuntimeException("Formulaire '{$formSlug}' non mappé — impossible d'importer un item Registration sans opération");
        }

        // Exercice: only for Membership items (null = don't set exercice on the ligne)
        $exercice = ($type === 'Membership') ? 'use_sync_exercice' : null;

        return [
            'item' => $item,
            'sous_categorie_id' => $sousCategorieId,
            'operation_id' => $operationId,
            'exercice' => $exercice,
        ];
    }

    private function resolveSousCategorie(string $itemType): int
    {
        $id = match ($itemType) {
            'Donation' => $this->parametres->sous_categorie_don_id,
            'Membership' => $this->parametres->sous_categorie_cotisation_id,
            'Registration' => $this->parametres->sous_categorie_inscription_id,
            default => $this->parametres->sous_categorie_don_id, // Fallback pour types inconnus (PaymentForm, etc.)
        };

        if ($id === null) {
            throw new \RuntimeException("Sous-catégorie non configurée pour le type '{$itemType}'");
        }

        return $id;
    }

    private function resolveModePaiement(array $payments): ModePaiement
    {
        $means = $payments[0]['paymentMeans'] ?? 'Card';

        return match ($means) {
            'Card' => ModePaiement::Cb,
            'Sepa' => ModePaiement::Prelevement,
            'Check' => ModePaiement::Cheque,
            'Cash' => ModePaiement::Especes,
            'BankTransfer' => ModePaiement::Virement,
            default => ModePaiement::Cb,
        };
    }

    private function buildLibelle(array $order): string
    {
        $formSlug = $order['formSlug'] ?? '';
        $formType = $order['formType'] ?? '';

        return "HelloAsso — {$formType} ({$formSlug})";
    }
}
