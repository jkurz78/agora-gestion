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

        $exerciceService = app(ExerciceService::class);

        try {
            $client = new HelloAssoApiClient($parametres);

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
            'rapprochementsCreated' => 0,
            'cashoutsIncomplets' => [],
            'cashoutSkipped' => false,
        ];

        // Cashout sync — fetch payments with extended range (N-1 → N)
        if ($parametres->compte_versement_id === null) {
            $this->result['cashoutSkipped'] = true;
        } else {
            try {
                $rangePrev = $exerciceService->dateRange($this->exercice - 1);
                $paymentsFrom = $rangePrev['start']->toDateString();

                $payments = $client->fetchPayments($paymentsFrom, $to);
                $cashOuts = HelloAssoApiClient::extractCashOutsFromPayments($payments);
                $cashoutResult = $syncService->synchroniserCashouts($cashOuts, $this->exercice);

                $this->result['virementsCreated'] = $cashoutResult['virements_created'];
                $this->result['virementsUpdated'] = $cashoutResult['virements_updated'];
                $this->result['rapprochementsCreated'] = $cashoutResult['rapprochements_created'];
                $this->result['cashoutsIncomplets'] = $cashoutResult['cashouts_incomplets'];

                if (! empty($cashoutResult['errors'])) {
                    $this->result['errors'] = array_merge($this->result['errors'], $cashoutResult['errors']);
                }
            } catch (\RuntimeException $e) {
                $this->result['errors'][] = "Cashouts : {$e->getMessage()}";
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
