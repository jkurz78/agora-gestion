<?php

declare(strict_types=1);

namespace App\Livewire\Parametres;

use App\Models\HelloAssoParametres;
use App\Models\Tiers;
use App\Services\ExerciceService;
use App\Services\HelloAssoApiClient;
use App\Services\HelloAssoTiersResolver;
use Illuminate\Contracts\View\View;
use Livewire\Component;

final class HelloassoTiersRapprochement extends Component
{
    public int $exercice;

    /** @var list<array> */
    public array $linked = [];

    /** @var list<array> */
    public array $unlinked = [];

    /** @var array<string, array> Données payer indexées par email, pour la création */
    public array $payerData = [];

    public bool $fetched = false;

    public ?string $erreur = null;

    /** @var array<string, string> Recherche par email de personne */
    public array $recherche = [];

    /** @var array<string, list<array{id: int, name: string}>> Résultats de recherche */
    public array $resultatsRecherche = [];

    public function mount(): void
    {
        $this->exercice = app(ExerciceService::class)->current();
    }

    public function fetchTiers(): void
    {
        $this->erreur = null;
        $this->fetched = false;

        $parametres = HelloAssoParametres::where('association_id', 1)->first();
        if ($parametres === null || $parametres->client_id === null) {
            $this->erreur = 'Paramètres HelloAsso non configurés.';

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

        $resolver = new HelloAssoTiersResolver;
        $persons = $resolver->extractPersons($orders);
        $result = $resolver->resolve($persons);

        $this->linked = $result['linked'];
        $this->unlinked = $result['unlinked'];
        $this->fetched = true;

        // Store payer data for creation (address info)
        $this->payerData = [];
        foreach ($orders as $order) {
            $payer = $order['payer'] ?? null;
            if ($payer && ! empty($payer['email'])) {
                $email = strtolower(trim($payer['email']));
                if (! isset($this->payerData[$email])) {
                    $this->payerData[$email] = $payer;
                }
            }
        }
    }

    public function rechercherTiers(string $email): void
    {
        $query = trim($this->recherche[$email] ?? '');
        if (mb_strlen($query) < 2) {
            $this->resultatsRecherche[$email] = [];

            return;
        }

        $results = Tiers::where(function ($q) use ($query) {
            $q->where('nom', 'like', "%{$query}%")
                ->orWhere('prenom', 'like', "%{$query}%")
                ->orWhere('email', 'like', "%{$query}%")
                ->orWhere('entreprise', 'like', "%{$query}%");
        })
            ->limit(10)
            ->get()
            ->map(fn (Tiers $t) => ['id' => $t->id, 'name' => $t->displayName()])
            ->all();

        $this->resultatsRecherche[$email] = $results;
    }

    public function associer(string $email, int $tiersId): void
    {
        $tiers = Tiers::findOrFail($tiersId);

        // Capture person data BEFORE removing from unlinked
        $person = collect($this->unlinked)->firstWhere('email', $email);

        $tiers->update([
            'est_helloasso' => true,
            'email' => $email,
        ]);

        // Move from unlinked to linked
        $this->unlinked = collect($this->unlinked)
            ->reject(fn (array $p) => $p['email'] === $email)
            ->values()
            ->all();

        $this->linked[] = [
            'email' => $email,
            'firstName' => $person['firstName'] ?? '',
            'lastName' => $person['lastName'] ?? '',
            'tiers_id' => $tiers->id,
            'tiers_name' => $tiers->displayName(),
        ];
    }

    public function creer(string $email): void
    {
        $person = collect($this->unlinked)->firstWhere('email', $email);
        if ($person === null) {
            return;
        }

        $payer = $this->payerData[$email] ?? [];

        $tiers = Tiers::create([
            'type' => 'particulier',
            'nom' => $person['lastName'],
            'prenom' => $person['firstName'],
            'email' => $email,
            'adresse_ligne1' => $payer['address'] ?? null,
            'ville' => $payer['city'] ?? null,
            'code_postal' => $payer['zipCode'] ?? null,
            'pays' => $payer['country'] ?? null,
            'est_helloasso' => true,
            'pour_recettes' => true,
        ]);

        $this->unlinked = collect($this->unlinked)
            ->reject(fn (array $p) => $p['email'] === $email)
            ->values()
            ->all();

        $this->linked[] = [
            'email' => $email,
            'firstName' => $person['firstName'],
            'lastName' => $person['lastName'],
            'tiers_id' => $tiers->id,
            'tiers_name' => $tiers->displayName(),
        ];
    }

    public function ignorer(string $email): void
    {
        $this->unlinked = collect($this->unlinked)
            ->reject(fn (array $p) => $p['email'] === $email)
            ->values()
            ->all();
    }

    public function render(): View
    {
        $exercices = app(ExerciceService::class)->available(5);

        return view('livewire.parametres.helloasso-tiers-rapprochement', [
            'exercices' => $exercices,
        ]);
    }
}
