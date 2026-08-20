<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ModePaiement;
use App\Enums\SensVentilation;
use App\Enums\StatutReglement;
use App\Enums\UsageComptable;
use App\Models\Compte;
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
use App\Observers\AdhesionTransactionLigneObserver;
use App\Services\Compta\TransactionConverter;
use App\Tenant\TenantContext;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class HelloAssoSyncService
{
    /** @var array<string, int> Cache formSlug → operation_id */
    private array $formMappingCache = [];

    /** @var array<int, ?int> operation_id => compte_id (ventilation du typeOperation) */
    private array $operationCompteCache = [];

    /** @var array<string, ?array<string, mixed>> Cache form_slug → fetchFormDetail result */
    private array $formDetailsCache = [];

    /**
     * Compte de contrepartie des gratuités (usage Gratuite, ex. 709A).
     * Résolu au plus une fois pour toute la synchro — mémoïsé avec un flag
     * distinct pour ne pas re-résoudre à chaque commande une fois établi qu'il
     * vaut null (association sans compte de gratuité configuré).
     */
    private ?int $compteGratuiteId = null;

    private bool $compteGratuiteResolved = false;

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

        // Skip si form Membership/Donation sans ventilation configurée.
        // DC-10a — la ventilation est portée par compte_id (source unique).
        if ($formMapping !== null
            && in_array($formMapping->form_type, ['Membership', 'Donation'], true)
            && $formMapping->compte_id === null) {
            $result['skipped']++;

            return $result;
        }

        // Skip si form Event sans operation_id configurée. Note : 'Event' est le
        // form_type renvoyé par HelloAsso v5 (pas 'Registration' — c'est item.type).
        if ($formMapping !== null
            && $formMapping->form_type === 'Event'
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

            // Pre-validate: resolve comptes and opérations for all items
            // For Membership items, auto-create formule before creating lignes
            // (so the AdhesionTransactionLigneObserver can find it)
            $resolvedItems = [];
            foreach ($items as $item) {
                $resolved = $this->resolveItem($item, $order['formSlug']);

                // Auto-création formule pour Membership
                if ($formMapping !== null
                    && $formMapping->form_type === 'Membership'
                    && isset($item['tierId'])
                    && $formMapping->compte_id !== null) {
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
                                (int) $formMapping->compte_id,
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

                // Suspendre l'observer adhésion pendant tout le lot d'upserts de la
                // commande (lignes ET recalcul de montant_total). L'observer se
                // déclenche à CHAQUE TransactionLigne sauvée et fige l'adhésion sur le
                // premier état qu'il voit : à l'upsert de la ligne parente, elle porte
                // déjà son compte de cotisation et son crédit BRUT, alors que la ligne
                // de remise et les options n'existent pas encore. `montant_facial`
                // figeait alors la valeur brute et n'était plus jamais corrigé (Tâche
                // 11, régression HA-55698 : brut 35,00 € au lieu du net 12,00 €).
                // Motif copié de AdhesionService::creerTransactionPaiement.
                AdhesionTransactionLigneObserver::$suppress = true;

                try {
                    // Upsert TransactionLignes — 1 ligne parent (brut) par item, +1 ligne de
                    // remise si l'item porte un discount, + 1 ligne par option imbriquée.
                    foreach ($resolvedItems as $resolved) {
                        $item = $resolved['item'];

                        // Ligne parent (cotisation/inscription/don) — montant BRUT, avant
                        // remise. `initialAmount` n'est absent que sur les items sans remise
                        // (fallback sur `amount`, alors identique au brut) — jamais l'inverse.
                        // Sur une commande entièrement remisée, item.amount vaut 0 et ne peut
                        // pas porter le produit : c'est le bug qui laissait la transaction 120
                        // sans écriture. La ligne de remise ci-dessous restaure le net.
                        $this->upsertLigne(
                            tx: $tx,
                            resolved: $resolved,
                            lineKey: 'parent',
                            optionId: null,
                            compteId: $resolved['compte_id'],
                            montantCentimes: (int) ($item['initialAmount'] ?? $item['amount']),
                            sens: SensVentilation::Credit,
                            notes: null,
                            result: $result,
                        );

                        // Ligne de remise — au débit, sur le compte de gratuité (709A), avec
                        // le même operation_id que la ligne parente (même item).
                        $discountCentimes = (int) ($item['discount']['amount'] ?? 0);
                        if ($discountCentimes > 0) {
                            $compteGratuiteId = $this->resolveCompteGratuiteId();

                            if ($compteGratuiteId === null) {
                                $code = $item['discount']['code'] ?? '?';

                                throw new \RuntimeException(
                                    "Une remise (code {$code}) a été appliquée sur l'item '{$item['name']}' ".
                                    "mais aucun compte de gratuité n'est configuré — sans lui, cette remise ne ".
                                    'serait comptabilisée nulle part. Configurez-le dans Paramètres → '.
                                    'Comptabilité → Usages comptables (usage « Gratuités accordées »).'
                                );
                            }

                            $this->upsertLigne(
                                tx: $tx,
                                resolved: $resolved,
                                lineKey: 'discount',
                                optionId: null,
                                compteId: $compteGratuiteId,
                                montantCentimes: $discountCentimes,
                                sens: SensVentilation::Debit,
                                notes: $this->buildDiscountNotes($item),
                                result: $result,
                                discountCode: $item['discount']['code'] ?? null,
                            );
                        }

                        // Lignes options — 1 ligne par option imbriquée
                        foreach ($item['options'] ?? [] as $opt) {
                            $optionId = (int) $opt['optionId'];
                            $this->upsertLigne(
                                tx: $tx,
                                resolved: $resolved,
                                lineKey: 'option:'.$optionId,
                                optionId: $optionId,
                                compteId: $resolved['compte_id'],
                                montantCentimes: (int) ($opt['amount'] ?? 0),
                                sens: SensVentilation::Credit,
                                notes: 'Option : '.($opt['name'] ?? '?'),
                                result: $result,
                            );
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

                    // Recalculate montant_total from actual lignes (handles split groups).
                    // Net (Σcrédit − Σdébit), restreint aux lignes de ventilation HelloAsso
                    // (helloasso_item_id non null) : sur un re-sync, la transaction porte déjà
                    // les lignes PD-only (411/401/5xxx) posées par le converter au tour
                    // précédent, dont le débit/crédit ne doit pas entrer dans ce total. La
                    // brute seule (montant de la ligne parent) ne convient plus non plus
                    // depuis l'introduction de la ligne de remise : une remise totale porte
                    // désormais un parent au brut ET une remise au débit qui doivent se
                    // compenser (ex. HA-55698 : 35,00 € parent − 35,00 € remise + 12,00 €
                    // option = 12,00 €, pas 47,00 €).
                    $totalCredit = (float) $tx->lignes()->whereNotNull('helloasso_item_id')->sum('credit');
                    $totalDebit = (float) $tx->lignes()->whereNotNull('helloasso_item_id')->sum('debit');
                    $tx->update([
                        'montant_total' => round($totalCredit - $totalDebit, 2),
                    ]);
                } finally {
                    AdhesionTransactionLigneObserver::$suppress = false;
                }

                // Créer/actualiser l'adhésion une fois le snapshot final connu — toutes
                // les lignes (parent, remise, options) écrites et montant_total
                // recalculé (Tâche 11). AdhesionService::creerDepuisTransaction est
                // idempotent (lookup par transaction_id) : sans effet sur un re-sync
                // ou une commande sans ligne de cotisation.
                app(AdhesionService::class)->creerDepuisTransaction($tx->fresh());

                // Enrichissement partie double via le converter (même chemin que le backfill).
                // Idempotent : skip si déjà equilibree=true (re-sync HA).
                if (! $tx->equilibree) {
                    try {
                        $tx->refresh();
                        app(TransactionConverter::class)->convertir($tx);
                    } catch (\Throwable $e) {
                        Log::warning('[HelloAsso] Enrichissement PD échoué — la transaction reste legacy', [
                            'transaction_id' => $tx->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }

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
     * Resolve compte de ventilation and opération for an item.
     *
     * @return array{item: array, compte_id: int, operation_id: ?int}
     */
    private function resolveItem(array $item, string $formSlug): array
    {
        $type = $item['type'] ?? 'Donation';
        $operationId = $this->formMappingCache[$formSlug] ?? null;
        $formMapping = null;

        // Registration items require an operation
        if ($type === 'Registration' && $operationId === null) {
            throw new \RuntimeException("Formulaire '{$formSlug}' non mappé — impossible d'importer un item Registration sans opération");
        }

        $compteId = null;

        // Cas spécial : un don additionnel (item.type='Donation') dans un form NON Donation
        // (Membership ou Event) ne suit PAS le compte de la cotisation/opération — il
        // atterrit dans le compte fallback "don additionnel" (fiscalement indépendant).
        // Doit passer AVANT la résolution opération sinon Event mappé à une opération
        // masquerait le fallback (bug observé sur HA-52045).
        // Note : on garde le rattachement à l'opération (operation_id) pour la traçabilité ;
        // la catégorisation fiscale est portée par compte_id.
        if ($type === 'Donation') {
            $formMapping = HelloAssoFormMapping::where('form_slug', $formSlug)->first();
            if ($formMapping?->form_type !== 'Donation') {
                $compteId = $this->parametres->compte_don_id;

                // Fail-closed : sans compte de fallback, la suite de la résolution
                // redescendrait sur le compte du formulaire et rangerait le don en
                // cotisation/inscription — silencieusement (régression HA-82469813).
                if ($compteId === null) {
                    throw new \RuntimeException("Formulaire '{$formSlug}' : un don additionnel a été reçu alors qu'aucun compte de fallback « don additionnel » n'est configuré — renseignez-le dans Paramètres → HelloAsso avant de synchroniser.");
                }
            }
        }

        // Sinon : compte de l'opération si form mappé à une opération
        if ($compteId === null && $operationId !== null) {
            $compteId = $this->getOperationCompteId($operationId);
        }

        // Sinon : fallback sur le compte configuré au niveau du form mapping
        if ($compteId === null) {
            $formMapping ??= HelloAssoFormMapping::where('form_slug', $formSlug)->first();
            $compteId = $formMapping?->compte_id;
        }

        if ($compteId === null) {
            throw new \RuntimeException("Compte non configuré pour le formulaire '{$formSlug}' (type item : '{$type}') — configurez le compte sur le mapping de formulaire.");
        }

        return [
            'item' => $item,
            'compte_id' => (int) $compteId,
            'operation_id' => $operationId,
            'helloasso_tier_id' => isset($item['tierId']) ? (int) $item['tierId'] : null,
        ];
    }

    private function getOperationCompteId(int $operationId): ?int
    {
        if (! array_key_exists($operationId, $this->operationCompteCache)) {
            $this->operationCompteCache[$operationId] = Operation::with('typeOperation')
                ->find($operationId)
                ?->typeOperation
                ?->compte_id;
        }

        return $this->operationCompteCache[$operationId];
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
        int $compteId,
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
                'compte_id' => $compteId,
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
     * Upsert une ligne de transaction HelloAsso.
     *
     * La clé d'idempotence est le couple (helloasso_item_id, helloasso_line_key) —
     * $lineKey vaut 'parent', 'discount' ou 'option:{id}' (Tâche 9, généralise le
     * correctif d'ordonnancement du commit 1db787b7). Un item et sa remise ne
     * portent jamais d'option (helloasso_option_id NULL pour les deux) : c'est
     * précisément la collision que l'ancien lookup sur (item_id, option_id IS NULL)
     * ne pouvait pas lever — d'où le nouveau discriminant explicite.
     *
     * @param  array{tx_created: int, tx_updated: int, lignes_created: int, lignes_updated: int, participants_created: int, skipped: int}  $result
     */
    private function upsertLigne(
        Transaction $tx,
        array $resolved,
        string $lineKey,
        ?int $optionId,
        int $compteId,
        int $montantCentimes,
        SensVentilation $sens,
        ?string $notes,
        array &$result,
        ?string $discountCode = null,
    ): void {
        $item = $resolved['item'];
        $montantEuros = round($montantCentimes / 100, 2);

        $existingLigne = TransactionLigne::withTrashed()
            ->where('helloasso_item_id', $item['id'])
            ->where('helloasso_line_key', $lineKey)
            ->first();

        if ($existingLigne?->trashed()) {
            $existingLigne->restore();
        }

        // DC-10a — ligne compte-first : compte_id + debit/credit posés dès la
        // création. Une ligne à 0 € reste sans compte : l'invariant XOR interdit
        // compte_id avec debit = credit = 0, et une écriture nulle n'a pas de
        // valeur comptable.
        $compteIdEffectif = $montantEuros != 0.0 ? $compteId : null;
        $estDebit = $compteIdEffectif !== null && $sens === SensVentilation::Debit;
        $estCredit = $compteIdEffectif !== null && $sens === SensVentilation::Credit;

        $data = [
            'transaction_id' => $tx->id,
            'compte_id' => $compteIdEffectif,
            'operation_id' => $resolved['operation_id'],
            'montant' => $montantEuros,
            'debit' => $estDebit ? $montantEuros : 0,
            'credit' => $estCredit ? $montantEuros : 0,
            'helloasso_tier_id' => $resolved['helloasso_tier_id'],
            'helloasso_discount_code' => $discountCode,
            'notes' => $notes,
        ];

        if ($existingLigne) {
            $existingLigne->update($data);
            $result['lignes_updated']++;
        } else {
            TransactionLigne::create($data + [
                'helloasso_item_id' => (int) $item['id'],
                'helloasso_option_id' => $optionId,
                // Discriminant de ligne (migration 2026_08_20_100001). Obligatoire dès
                // qu'une ligne porte un helloasso_item_id — la contrainte CHECK
                // chk_tl_helloasso_line_key_presence le refuse autrement sous MySQL.
                'helloasso_line_key' => $lineKey,
            ]);
            $result['lignes_created']++;
        }
    }

    /**
     * Résout, une seule fois pour toute la synchro, le compte de contrepartie
     * des gratuités (usage Gratuite, ex. 709A). Null si non configuré — c'est au
     * caller de décider si c'est bloquant (ça ne l'est que si une remise existe).
     */
    private function resolveCompteGratuiteId(): ?int
    {
        if (! $this->compteGratuiteResolved) {
            $this->compteGratuiteResolved = true;
            $this->compteGratuiteId = Compte::forUsage(UsageComptable::Gratuite)->first()?->id;
        }

        return $this->compteGratuiteId;
    }

    /**
     * Construit la note de la ligne de remise d'un item HelloAsso.
     *
     * Le libellé suit le type réel de l'item — jamais « Cotisation » en dur :
     * la transaction 120 en production est un item Registration (form Event) et
     * affichait pourtant « Cotisation offerte », un bug de ce libellé figé.
     */
    private function buildDiscountNotes(array $item): string
    {
        $label = match ($item['type'] ?? null) {
            'Membership' => 'Cotisation',
            'Registration' => 'Inscription',
            default => 'Participation',
        };
        $code = $item['discount']['code'] ?? '?';

        return "{$label} offerte par code promo : {$code}";
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
