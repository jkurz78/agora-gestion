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
        ];
    }

    public function render(): View
    {
        return view('livewire.parametres.helloasso-sync', [
            'exercices' => app(ExerciceService::class)->available(5),
        ]);
    }
}
