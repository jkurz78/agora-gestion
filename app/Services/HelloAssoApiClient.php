<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\HelloAssoParametres;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class HelloAssoApiClient
{
    private string $baseUrl;

    private string $clientId;

    private string $clientSecret;

    private string $organisationSlug;

    private ?string $accessToken = null;

    public function __construct(HelloAssoParametres $parametres)
    {
        $this->baseUrl = $parametres->environnement->baseUrl();
        $this->clientId = $parametres->client_id;
        $this->clientSecret = $parametres->client_secret;
        $this->organisationSlug = $parametres->organisation_slug;
    }

    /**
     * Fetch all orders for a date range, handling pagination.
     *
     * @return list<array<string, mixed>>
     */
    public function fetchOrders(string $from, string $to): array
    {
        $this->authenticate();

        return $this->fetchPaginated(
            "/v5/organizations/{$this->organisationSlug}/orders",
            ['from' => $from, 'to' => $to],
        );
    }

    /**
     * Fetch all forms for the organization.
     *
     * @return list<array<string, mixed>>
     */
    public function fetchForms(): array
    {
        $this->authenticate();

        return $this->fetchPaginated(
            "/v5/organizations/{$this->organisationSlug}/forms",
        );
    }

    /**
     * Extract cash-outs from orders by grouping payments by idCashOut.
     *
     * HelloAsso API has no list endpoint for cash-outs — the data lives
     * on payment objects (idCashOut, cashOutDate, cashOutState).
     *
     * @param  list<array<string, mixed>>  $orders
     * @return list<array{id: int, date: string, amount: int, payments: list<array{id: int}>}>
     */
    public static function extractCashOutsFromOrders(array $orders): array
    {
        $groups = []; // idCashOut → [date, totalCents, paymentIds[]]

        foreach ($orders as $order) {
            foreach ($order['payments'] ?? [] as $payment) {
                $cashOutId = $payment['idCashOut'] ?? null;
                if ($cashOutId === null) {
                    continue;
                }
                $state = $payment['cashOutState'] ?? null;
                if ($state !== 'CashedOut') {
                    continue; // Only process completed cash-outs
                }

                if (! isset($groups[$cashOutId])) {
                    $groups[$cashOutId] = [
                        'date' => $payment['cashOutDate'] ?? $payment['date'] ?? now()->toIso8601String(),
                        'totalCents' => 0,
                        'payments' => [],
                    ];
                }

                $groups[$cashOutId]['totalCents'] += $payment['amount'] ?? 0;
                $groups[$cashOutId]['payments'][] = ['id' => $payment['id']];
            }
        }

        $result = [];
        foreach ($groups as $cashOutId => $group) {
            $result[] = [
                'id' => $cashOutId,
                'date' => $group['date'],
                'amount' => $group['totalCents'],
                'payments' => $group['payments'],
            ];
        }

        return $result;
    }

    private function authenticate(): void
    {
        if ($this->accessToken !== null) {
            return;
        }

        try {
            $response = Http::timeout(10)->asForm()->post("{$this->baseUrl}/oauth2/token", [
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'grant_type' => 'client_credentials',
            ]);
        } catch (ConnectionException) {
            throw new RuntimeException('Impossible de joindre HelloAsso : timeout ou erreur réseau');
        }

        if ($response->failed()) {
            throw new RuntimeException("Authentification HelloAsso échouée (HTTP {$response->status()})");
        }

        $token = $response->json('access_token');
        if (! is_string($token) || $token === '') {
            throw new RuntimeException('Réponse HelloAsso inattendue : token manquant');
        }

        $this->accessToken = $token;
    }

    /**
     * @param  array<string, string>  $params
     * @return list<array<string, mixed>>
     */
    private function fetchPaginated(string $path, array $params = []): array
    {
        $all = [];
        $continuationToken = null;

        do {
            $query = array_merge($params, ['pageSize' => 100]);
            if ($continuationToken !== null) {
                $query['continuationToken'] = $continuationToken;
            }

            try {
                $response = Http::timeout(30)
                    ->withToken($this->accessToken)
                    ->get("{$this->baseUrl}{$path}", $query);
            } catch (ConnectionException) {
                throw new RuntimeException("Erreur réseau lors de l'appel à {$path}");
            }

            if ($response->failed()) {
                throw new RuntimeException("Erreur API HelloAsso {$path} (HTTP {$response->status()})");
            }

            $data = $response->json('data', []);
            if (empty($data)) {
                break;
            }

            array_push($all, ...$data);

            $continuationToken = $response->json('pagination.continuationToken');
        } while (true);

        return $all;
    }
}
