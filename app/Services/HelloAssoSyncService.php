<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ModePaiement;
use App\Enums\StatutReglement;
use App\Models\CompteBancaire;
use App\Models\FormuleAdhesion;
use App\Models\HelloAssoFormMapping;
use App\Models\HelloAssoParametres;
use App\Models\Operation;
use App\Models\Participant;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\VirementInterne;
use App\Tenant\TenantContext;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class HelloAssoSyncService
{
    /** @var array<string, int> Cache formSlug → operation_id */
    private array $formMappingCache = [];

    /** @var array<int, ?int> operation_id => sous_categorie_id */
    private array $operationSousCategorieCache = [];

    /** @var array<string, ?array<string, mixed>> Cache form_slug → fetchFormDetail result */
    private array $formDetailsCache = [];

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
     * Import HelloAsso orders into local transactions.
     *
     * @param  list<array<string, mixed>>  $orders
     */
    public function synchroniser(array $orders, int $exercice): HelloAssoSyncResult
    {
        $txCreated = 0;
        $txUpdated = 0;
        $lignesCreated = 0;
        $lignesUpdated = 0;
        $participantsCreated = 0;
        $skipped = 0;
        $errors = [];

        foreach ($orders as $order) {
            try {
                $result = $this->processOrder($order, $exercice);
                $txCreated += $result['tx_created'];
                $txUpdated += $result['tx_updated'];
                $lignesCreated += $result['lignes_created'];
                $lignesUpdated += $result['lignes_updated'];
                $participantsCreated += $result['participants_created'];
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
            participantsCreated: $participantsCreated,
            ordersSkipped: $skipped,
            errors: $errors,
        );
    }

    /**
     * @return array{tx_created: int, tx_updated: int, lignes_created: int, lignes_updated: int, participants_created: int, skipped: int}
     */
    private function processOrder(array $order, int $exercice): array
    {
        $result = ['tx_created' => 0, 'tx_updated' => 0, 'lignes_created' => 0, 'lignes_updated' => 0, 'participants_created' => 0, 'skipped' => 0];

        $formSlug = $order['formSlug'] ?? '';

        // Load form mapping for skip guards and auto-creation
        $formMapping = HelloAssoFormMapping::query()
            ->where('form_slug', $formSlug)
            ->first();

        // Skip si form ignoré
        if ($formMapping !== null && $formMapping->ignore) {
            $result['skipped']++;

            return $result;
        }

        // Skip si form Membership/Donation sans sous_categorie_id configurée
        if ($formMapping !== null
            && in_array($formMapping->form_type, ['Membership', 'Donation'], true)
            && $formMapping->sous_categorie_id === null) {
            $result['skipped']++;

            return $result;
        }

        // Skip si form Registration sans operation_id configurée
        if ($formMapping !== null
            && $formMapping->form_type === 'Registration'
            && $formMapping->operation_id === null) {
            $result['skipped']++;

            return $result;
        }

        // Group items by beneficiary nom+prénom
        $groups = $this->groupItemsByBeneficiary($order);

        $orderDate = Carbon::parse($order['date'])->toDateString();
        $modePaiement = $this->resolveModePaiement($order['payments'] ?? []);

        foreach ($groups as $key => $group) {
            $items = $group['items'];
            $firstName = $group['firstName'];
            $lastName = $group['lastName'];

            $tiers = Tiers::whereRaw('LOWER(helloasso_nom) = ?', [strtolower($lastName)])
                ->whereRaw('LOWER(helloasso_prenom) = ?', [strtolower($firstName)])
                ->where('est_helloasso', true)
                ->first();
            if ($tiers === null) {
                throw new \RuntimeException("Tiers non trouvé pour {$firstName} {$lastName} — rapprochez d'abord les tiers");
            }

            // Pre-validate: resolve sous-catégories and opérations for all items
            // For Membership items, auto-create formule before creating lignes
            // (so the AdhesionTransactionLigneObserver can find it)
            $resolvedItems = [];
            foreach ($items as $item) {
                $resolved = $this->resolveItem($item, $order['formSlug']);

                // Auto-création formule pour Membership
                if ($formMapping !== null
                    && $formMapping->form_type === 'Membership'
                    && isset($item['tierId'])
                    && $formMapping->sous_categorie_id !== null) {
                    $itemFormSlug = $formMapping->form_slug;
                    if (! isset($this->formDetailsCache[$itemFormSlug])) {
                        try {
                            $client = new HelloAssoApiClient($this->parametres);
                            $this->formDetailsCache[$itemFormSlug] = $client->fetchFormDetail($formMapping->form_type, $itemFormSlug);
                        } catch (\Throwable $e) {
                            // Log explicite : sans ça, l'auto-création de formule échoue silencieusement
                            // et la priorité 2 du resolver fallback sur la sous-cat → mauvaise formule appliquée.
                            Log::warning(
                                "HelloAsso fetchFormDetail failed for form {$itemFormSlug}: ".$e->getMessage()
                            );
                            $this->formDetailsCache[$itemFormSlug] = null;
                        }
                    }
                    $formDetail = $this->formDetailsCache[$itemFormSlug];
                    if ($formDetail !== null) {
                        $tier = collect($formDetail['tiers'] ?? [])->firstWhere('id', (int) $item['tierId']);
                        if ($tier !== null) {
                            $this->firstOrCreateFormule(
                                $itemFormSlug,
                                $tier,
                                $formDetail['validityType'] ?? null,
                                (int) $formMapping->sous_categorie_id,
                                $formDetail['startDate'] ?? null,
                                $formDetail['endDate'] ?? null,
                                $formMapping->form_title ?? $formDetail['title'] ?? null,
                            );
                        }
                    }
                }

                $resolvedItems[] = $resolved;
            }

            DB::transaction(function () use ($order, $orderDate, $modePaiement, $tiers, $resolvedItems, $formMapping, &$result) {
                // Upsert Transaction (montant_total recalculated after lignes)
                $existing = Transaction::withTrashed()
                    ->where('helloasso_order_id', $order['id'])
                    ->where('tiers_id', $tiers->id)
                    ->first();

                if ($existing?->trashed()) {
                    $result['skipped']++;

                    return;
                }

                if ($existing) {
                    $data = [
                        'date' => $orderDate,
                        'mode_paiement' => $modePaiement,
                        'libelle' => $this->buildLibelle($order),
                        'helloasso_form_slug' => $order['formSlug'] ?? null,
                    ];
                    if (empty($existing->reference)) {
                        $data['reference'] = $this->buildReference($order);
                    }
                    if ($existing->helloasso_payment_id === null && isset($order['payments'][0]['id'])) {
                        $data['helloasso_payment_id'] = $order['payments'][0]['id'];
                    }
                    $existing->update($data);
                    $result['tx_updated']++;
                    $tx = $existing;
                } else {
                    $tx = Transaction::create([
                        'type' => 'recette',
                        'date' => $orderDate,
                        'libelle' => $this->buildLibelle($order),
                        'montant_total' => 0,
                        'mode_paiement' => $modePaiement,
                        'statut_reglement' => $this->resolveStatutReglement($modePaiement)->value,
                        'tiers_id' => $tiers->id,
                        'reference' => $this->buildReference($order),
                        'compte_id' => $this->resolveCompteId($modePaiement),
                        'helloasso_order_id' => $order['id'],
                        'helloasso_payment_id' => $order['payments'][0]['id'] ?? null,
                        'helloasso_form_slug' => $order['formSlug'] ?? null,
                        'saisi_par' => auth()->id(),
                        'numero_piece' => app(NumeroPieceService::class)->assign(Carbon::parse($orderDate)),
                    ]);
                    $result['tx_created']++;
                }

                // Upsert TransactionLignes
                foreach ($resolvedItems as $resolved) {
                    $item = $resolved['item'];
                    $montantEuros = round($item['amount'] / 100, 2);

                    $existingLigne = TransactionLigne::withTrashed()
                        ->where('helloasso_item_id', $item['id'])
                        ->first();

                    if ($existingLigne?->trashed()) {
                        $existingLigne->restore();
                    }

                    if ($existingLigne) {
                        $existingLigne->update([
                            'transaction_id' => $tx->id,
                            'sous_categorie_id' => $resolved['sous_categorie_id'],
                            'operation_id' => $resolved['operation_id'],
                            'montant' => $montantEuros,
                            'helloasso_tier_id' => $resolved['helloasso_tier_id'],
                        ]);
                        $result['lignes_updated']++;
                    } else {
                        TransactionLigne::create([
                            'transaction_id' => $tx->id,
                            'sous_categorie_id' => $resolved['sous_categorie_id'],
                            'operation_id' => $resolved['operation_id'],
                            'montant' => $montantEuros,
                            'helloasso_item_id' => $item['id'],
                            'helloasso_tier_id' => $resolved['helloasso_tier_id'],
                        ]);
                        $result['lignes_created']++;
                    }
                }

                // Create Participant for Registration items
                foreach ($resolvedItems as $resolved) {
                    $item = $resolved['item'];
                    $type = $item['type'] ?? 'Donation';

                    if ($type === 'Registration' && $resolved['operation_id'] !== null) {
                        $created = Participant::firstOrCreate(
                            [
                                'tiers_id' => $tiers->id,
                                'operation_id' => $resolved['operation_id'],
                            ],
                            [
                                'date_inscription' => $orderDate,
                                'est_helloasso' => true,
                                'helloasso_item_id' => $item['id'],
                                'helloasso_order_id' => $order['id'],
                            ],
                        );
                        if ($created->wasRecentlyCreated) {
                            $result['participants_created']++;
                        }
                    }
                }

                // Recalculate montant_total from actual lignes (handles split groups)
                $tx->update([
                    'montant_total' => round((float) $tx->lignes()->sum('montant'), 2),
                ]);

                // Poser imported_at à la 1re importation réussie
                if ($formMapping !== null && $formMapping->imported_at === null && $result['tx_created'] > 0) {
                    $formMapping->update(['imported_at' => now()]);
                }
            });
        }

        return $result;
    }

    /**
     * Group items by beneficiary nom+prénom.
     *
     * @return array<string, array{firstName: string, lastName: string, items: list<array>}>
     */
    private function groupItemsByBeneficiary(array $order): array
    {
        $groups = [];

        foreach ($order['items'] as $item) {
            // Per-item user takes priority, fallback to order-level user/payer
            $person = $item['user'] ?? $order['user'] ?? $order['payer'] ?? null;
            if ($person === null || empty($person['lastName'])) {
                continue;
            }

            $firstName = trim($person['firstName'] ?? '');
            $lastName = trim($person['lastName'] ?? '');
            $key = strtolower($lastName).'|'.strtolower($firstName);

            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'firstName' => $firstName,
                    'lastName' => $lastName,
                    'items' => [],
                ];
            }

            $groups[$key]['items'][] = $item;
        }

        return $groups;
    }

    /**
     * Resolve sous-catégorie and opération for an item.
     *
     * @return array{item: array, sous_categorie_id: int, operation_id: ?int}
     */
    private function resolveItem(array $item, string $formSlug): array
    {
        $type = $item['type'] ?? 'Donation';
        $operationId = $this->formMappingCache[$formSlug] ?? null;

        // Registration items require an operation
        if ($type === 'Registration' && $operationId === null) {
            throw new \RuntimeException("Formulaire '{$formSlug}' non mappé — impossible d'importer un item Registration sans opération");
        }

        // Use operation's sous-catégorie if set, otherwise fall back to form mapping sous-catégorie
        $sousCategorieId = null;
        if ($operationId !== null) {
            $sousCategorieId = $this->getOperationSousCategorieId($operationId);
        }

        // Fallback : si l'item est un Donation dans un form qui n'est pas de type Donation,
        // utiliser la sous-catégorie de fallback "don additionnel" configurée dans les paramètres.
        if ($sousCategorieId === null && $type === 'Donation') {
            $formMapping = HelloAssoFormMapping::where('form_slug', $formSlug)->first();
            if ($formMapping?->form_type !== 'Donation') {
                $sousCategorieId = $this->parametres->sous_categorie_don_id;
            }
        }

        if ($sousCategorieId === null) {
            $formMapping ??= HelloAssoFormMapping::where('form_slug', $formSlug)->first();
            $sousCategorieId = $formMapping?->sous_categorie_id;
        }

        if ($sousCategorieId === null) {
            throw new \RuntimeException("Sous-catégorie non configurée pour le formulaire '{$formSlug}' (type item : '{$type}') — configurez la sous-catégorie sur le mapping de formulaire.");
        }

        return [
            'item' => $item,
            'sous_categorie_id' => $sousCategorieId,
            'operation_id' => $operationId,
            'helloasso_tier_id' => isset($item['tierId']) ? (int) $item['tierId'] : null,
        ];
    }

    private function getOperationSousCategorieId(int $operationId): ?int
    {
        if (! array_key_exists($operationId, $this->operationSousCategorieCache)) {
            $this->operationSousCategorieCache[$operationId] = Operation::with('typeOperation')
                ->find($operationId)
                ?->typeOperation
                ?->sous_categorie_id;
        }

        return $this->operationSousCategorieCache[$operationId];
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

    /**
     * Route la transaction vers le bon compte selon le mode de paiement.
     * CB/Prélèvement      → compte HelloAsso (cashout réconcilie)
     * Chèque/Espèces      → compte_versement_id (fallback compte_helloasso_id)
     * Virement            → compte_versement_id (fallback compte_helloasso_id)
     */
    private function resolveCompteId(ModePaiement $mode): int
    {
        return match ($mode) {
            ModePaiement::Cheque,
            ModePaiement::Especes,
            ModePaiement::Virement => $this->parametres->compte_versement_id ?? $this->parametres->compte_helloasso_id,
            default => $this->parametres->compte_helloasso_id,
        };
    }

    private function resolveStatutReglement(ModePaiement $mode): StatutReglement
    {
        return match ($mode) {
            ModePaiement::Cb,
            ModePaiement::Prelevement => StatutReglement::Recu,
            default => StatutReglement::EnAttente,
        };
    }

    /**
     * Désactive les formules HelloAsso dont le form_slug n'apparaît plus dans la liste active.
     * Appelé après synchronisation pour nettoyer les formules orphelines.
     */
    public function desactiverFormulesOrphelines(array $formSlugsActifs): int
    {
        return FormuleAdhesion::query()
            ->where('est_helloasso', true)
            ->where('actif', true)
            ->whereNotIn('helloasso_form_slug', $formSlugsActifs)
            ->update(['actif' => false]);
    }

    /**
     * Crée ou met à jour une FormuleAdhesion pour un couple (form_slug, tier_id) HelloAsso.
     *
     * @param  array<string, mixed>  $tier
     */
    private function firstOrCreateFormule(
        string $formSlug,
        array $tier,
        ?string $validityType,
        int $sousCategorieId,
        ?string $startDate = null,
        ?string $endDate = null,
        ?string $formTitle = null,
    ): ?FormuleAdhesion {
        $tierId = (int) ($tier['id'] ?? 0);
        if ($tierId === 0) {
            return null;
        }

        // Custom → durée avec dates fixes du form HelloAsso (pas de duree_mois)
        $mode = match ($validityType) {
            'MovingYear' => 'duree',
            'Custom' => 'duree',
            'Illimited' => 'illimite',
            default => 'exercice',
        };

        // Pour MovingYear : 12 mois glissants. Pour Custom : durée fixe via dates, pas de duree_mois.
        $dureeMois = ($validityType === 'MovingYear') ? 12 : null;

        // Le nom préfixe le label du palier par le titre du formulaire pour
        // disambiguer les formules issues de plusieurs forms HelloAsso (ex.
        // "Cotisation 2025-2026 — Bienfaiteur"). Sans préfixe si pas de titre.
        $tierLabel = $tier['label'] ?? "Palier {$tierId}";
        $nom = $formTitle !== null && $formTitle !== ''
            ? $formTitle.' — '.$tierLabel
            : $tierLabel;

        return FormuleAdhesion::updateOrCreate(
            [
                'helloasso_form_slug' => $formSlug,
                'helloasso_tier_id' => $tierId,
            ],
            [
                'association_id' => TenantContext::currentId(),
                'nom' => $nom,
                'mode' => $mode,
                'duree_mois' => $dureeMois,
                'helloasso_start_date' => $validityType === 'Custom' ? $startDate : null,
                'helloasso_end_date' => $validityType === 'Custom' ? $endDate : null,
                'montant_par_defaut' => isset($tier['price']) ? round($tier['price'] / 100, 2) : null,
                'deductible_fiscal' => (bool) ($tier['isEligibleTaxReceipt'] ?? false),
                'sous_categorie_id' => $sousCategorieId,
                'actif' => true,
                'est_helloasso' => true,
            ]
        );
    }

    private function buildReference(array $order): string
    {
        $paymentId = $order['payments'][0]['id'] ?? $order['id'];

        return "HA-{$paymentId}";
    }

    private function buildLibelle(array $order): string
    {
        $formSlug = $order['formSlug'] ?? '';
        $formType = $order['formType'] ?? '';

        return "HelloAsso — {$formType} ({$formSlug})";
    }

    /**
     * Import HelloAsso cash-outs: verify completeness, create virements + auto-locked rapprochements.
     *
     * @param  list<array<string, mixed>>  $cashOuts
     * @return array{virements_created: int, virements_updated: int, rapprochements_created: int, cashouts_incomplets: list<string>, info_exercice_precedent: list<string>, errors: list<string>}
     */
    public function synchroniserCashouts(array $cashOuts, ?int $exercice = null): array
    {
        $virementsCreated = 0;
        $rapprochementsCreated = 0;
        $cashoutsIncomplets = [];
        $errors = [];

        // Only warn about incomplete cashouts within the active exercice
        $exerciceRange = $exercice !== null
            ? app(ExerciceService::class)->dateRange($exercice)
            : null;

        // Sort by cashout date (chronological) for consistent rapprochement chain
        usort($cashOuts, fn ($a, $b) => strcmp($a['date'], $b['date']));

        foreach ($cashOuts as $cashOut) {
            try {
                $result = $this->processCashout($cashOut);
                $virementsCreated += $result['created'];
                $rapprochementsCreated += $result['rapprochement_created'];
                if ($result['incomplet'] !== null && $exerciceRange !== null) {
                    $cashOutDate = Carbon::parse($cashOut['date']);
                    if ($cashOutDate->between($exerciceRange['start'], $exerciceRange['end'])) {
                        $cashoutsIncomplets[] = $result['incomplet'];
                    }
                } elseif ($result['incomplet'] !== null) {
                    $cashoutsIncomplets[] = $result['incomplet'];
                }
            } catch (\Throwable $e) {
                $errors[] = "Cashout #{$cashOut['id']} : {$e->getMessage()}";
            }
        }

        return [
            'virements_created' => $virementsCreated,
            'virements_updated' => 0,
            'rapprochements_created' => $rapprochementsCreated,
            'cashouts_incomplets' => $cashoutsIncomplets,
            'info_exercice_precedent' => [],
            'errors' => $errors,
        ];
    }

    /**
     * @return array{created: int, rapprochement_created: int, incomplet: ?string}
     */
    private function processCashout(array $cashOut): array
    {
        $result = ['created' => 0, 'rapprochement_created' => 0, 'incomplet' => null];

        $cashOutDate = Carbon::parse($cashOut['date']);
        $montantEuros = round($cashOut['amount'] / 100, 2);

        // Collect payment IDs from the cashout
        $paymentIds = collect($cashOut['payments'] ?? [])->pluck('id')->filter()->all();

        // Find matching transactions in DB (no exercice filter)
        $transactions = Transaction::whereIn('helloasso_payment_id', $paymentIds)->get();

        // Update helloasso_cashout_id on found transactions (even if incomplete)
        Transaction::whereIn('helloasso_payment_id', $paymentIds)
            ->whereNull('helloasso_cashout_id')
            ->update(['helloasso_cashout_id' => $cashOut['id']]);

        // Check completeness
        $sumTransactions = round((float) $transactions->sum('montant_total'), 2);

        if (abs($sumTransactions - $montantEuros) > 0.01) {
            $result['incomplet'] = sprintf(
                'Cashout #%d incomplet : écart de %.2f € (versement %.2f €, transactions %.2f €)',
                $cashOut['id'],
                abs($montantEuros - $sumTransactions),
                $montantEuros,
                $sumTransactions,
            );

            return $result;
        }

        // Idempotence: check if virement already exists for this cashout
        $existingVirement = VirementInterne::where('helloasso_cashout_id', $cashOut['id'])->first();

        if ($existingVirement && $existingVirement->rapprochement_source_id !== null) {
            // Virement + rapprochement already exist → fully processed
            return $result;
        }

        // Complete → create virement (if needed) + auto-locked rapprochement
        $rapprochementService = app(RapprochementBancaireService::class);
        $compte = CompteBancaire::find($this->parametres->compte_helloasso_id);
        $soldeOuverture = $rapprochementService->calculerSoldeOuverture($compte);

        DB::transaction(function () use ($cashOut, $cashOutDate, $montantEuros, $transactions, $existingVirement, $rapprochementService, $compte, $soldeOuverture, &$result) {
            $virement = $existingVirement;

            if ($virement === null) {
                $virement = VirementInterne::create([
                    'date' => $cashOutDate->toDateString(),
                    'montant' => $montantEuros,
                    'compte_source_id' => $this->parametres->compte_helloasso_id,
                    'compte_destination_id' => $this->parametres->compte_versement_id,
                    'notes' => 'Versement HelloAsso du '.$cashOutDate->format('d/m/Y'),
                    'reference' => "HA-CO-{$cashOut['id']}",
                    'helloasso_cashout_id' => $cashOut['id'],
                    'saisi_par' => auth()->id() ?? 1,
                    'numero_piece' => app(NumeroPieceService::class)->assign($cashOutDate),
                ]);
                $result['created']++;
            }

            // Auto-locked rapprochement
            $rapprochementService->createVerrouilleAuto(
                compte: $compte,
                dateFin: $cashOutDate->toDateString(),
                soldeFin: $soldeOuverture,
                transactionIds: $transactions->pluck('id')->all(),
                virementId: $virement->id,
            );
            $result['rapprochement_created']++;
        });

        return $result;
    }
}
