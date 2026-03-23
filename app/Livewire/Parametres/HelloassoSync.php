<?php

declare(strict_types=1);

namespace App\Livewire\Parametres;

use App\Models\HelloAssoParametres;
use App\Services\ExerciceService;
use App\Services\HelloAssoApiClient;
use App\Services\HelloAssoSyncService;
use Illuminate\Contracts\View\View;
use Livewire\Component;

final class HelloassoSync extends Component
{
    public int $exercice;

    /** @var array<string, mixed>|null */
    public ?array $result = null;

    public ?string $erreur = null;

    public function mount(): void
    {
        $this->exercice = app(ExerciceService::class)->current();
    }

    public function synchroniser(): void
    {
        $this->erreur = null;
        $this->result = null;

        $parametres = HelloAssoParametres::where('association_id', 1)->first();
        if ($parametres === null || $parametres->client_id === null) {
            $this->erreur = 'Paramètres HelloAsso non configurés.';

            return;
        }

        if ($parametres->compte_helloasso_id === null) {
            $this->erreur = 'Compte HelloAsso non configuré. Configurez-le dans la section ci-dessus.';

            return;
        }

        try {
            $client = new HelloAssoApiClient($parametres);

            $exerciceService = app(ExerciceService::class);
            $range = $exerciceService->dateRange($this->exercice);
            $from = $range['start']->toDateString();
            $to = $range['end']->toDateString();

            $orders = $client->fetchOrders($from, $to);
        } catch (\RuntimeException $e) {
            $this->erreur = $e->getMessage();

            return;
        }

        $syncService = new HelloAssoSyncService($parametres);
        $syncResult = $syncService->synchroniser($orders, $this->exercice);
        $this->result = [
            'transactionsCreated' => $syncResult->transactionsCreated,
            'transactionsUpdated' => $syncResult->transactionsUpdated,
            'lignesCreated' => $syncResult->lignesCreated,
            'lignesUpdated' => $syncResult->lignesUpdated,
            'ordersSkipped' => $syncResult->ordersSkipped,
            'errors' => $syncResult->errors,
            'virementsCreated' => 0,
            'virementsUpdated' => 0,
            'integrityWarnings' => [],
            'cashoutSkipped' => false,
        ];

        // DEBUG: log cashout field stats across all payments
        $paymentStats = ['total' => 0, 'with_idCashOut' => 0, 'states' => []];
        $sampleCashOut = null;
        foreach ($orders as $o) {
            foreach ($o['payments'] ?? [] as $p) {
                $paymentStats['total']++;
                $state = $p['cashOutState'] ?? 'MISSING';
                $paymentStats['states'][$state] = ($paymentStats['states'][$state] ?? 0) + 1;
                if (isset($p['idCashOut'])) {
                    $paymentStats['with_idCashOut']++;
                    if ($sampleCashOut === null) {
                        $sampleCashOut = $p;
                    }
                }
            }
        }
        \Log::info('HelloAsso payment cashout stats', $paymentStats);
        if ($sampleCashOut !== null) {
            \Log::info('HelloAsso sample payment with idCashOut', $sampleCashOut);
        }

        // Cashout sync — extract cash-outs from the orders we already fetched
        if ($parametres->compte_versement_id === null) {
            $this->result['cashoutSkipped'] = true;
        } else {
            $cashOuts = HelloAssoApiClient::extractCashOutsFromOrders($orders);
            $cashoutResult = $syncService->synchroniserCashouts($cashOuts);

            $this->result['virementsCreated'] = $cashoutResult['virements_created'];
            $this->result['virementsUpdated'] = $cashoutResult['virements_updated'];
            $this->result['integrityWarnings'] = $cashoutResult['integrity_warnings'];

            if (! empty($cashoutResult['errors'])) {
                $this->result['errors'] = array_merge($this->result['errors'], $cashoutResult['errors']);
            }
        }
    }

    public function render(): View
    {
        return view('livewire.parametres.helloasso-sync', [
            'exercices' => app(ExerciceService::class)->available(5),
        ]);
    }
}
