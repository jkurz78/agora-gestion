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

    /**
     * @var list<array{email: string, firstName: string, lastName: string, tiers_id: ?int, tiers_name: ?string}>
     */
    public array $persons = [];

    /** @var array<int, ?int> index → selected tiers_id */
    public array $selectedTiers = [];

    /** @var array<string, array> Données payer indexées par email, pour la création */
    public array $payerData = [];

    public bool $fetched = false;

    public ?string $erreur = null;

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
        $extractedPersons = $resolver->extractPersons($orders);
        $result = $resolver->resolve($extractedPersons);

        // Build unified persons list: unlinked first, then linked
        $this->persons = [];
        $this->selectedTiers = [];
        $index = 0;

        // Unlinked persons (sorted first)
        foreach ($result['unlinked'] as $person) {
            $suggestedId = null;
            if (count($person['suggestions']) > 0) {
                $suggestedId = $person['suggestions'][0]['tiers_id'];
            }

            $this->persons[] = [
                'email' => $person['email'],
                'firstName' => $person['firstName'],
                'lastName' => $person['lastName'],
                'tiers_id' => null,
                'tiers_name' => null,
            ];
            $this->selectedTiers[$index] = $suggestedId;
            $index++;
        }

        // Linked persons (after unlinked)
        foreach ($result['linked'] as $person) {
            $this->persons[] = [
                'email' => $person['email'],
                'firstName' => $person['firstName'],
                'lastName' => $person['lastName'],
                'tiers_id' => $person['tiers_id'],
                'tiers_name' => $person['tiers_name'],
            ];
            $this->selectedTiers[$index] = $person['tiers_id'];
            $index++;
        }

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

    public function associer(int $index): void
    {
        $email = $this->persons[$index]['email'] ?? null;
        $tiersId = $this->selectedTiers[$index] ?? null;
        if ($tiersId === null || $email === null) {
            return;
        }

        $tiers = Tiers::findOrFail($tiersId);

        $tiers->update([
            'est_helloasso' => true,
            'email' => $email,
        ]);

        // Update the person in the list
        $this->persons = collect($this->persons)->map(function (array $p) use ($email, $tiers) {
            if ($p['email'] === $email) {
                $p['tiers_id'] = $tiers->id;
                $p['tiers_name'] = $tiers->displayName();
            }

            return $p;
        })->all();
    }

    public function creer(int $index): void
    {
        $person = $this->persons[$index] ?? null;
        if ($person === null) {
            return;
        }

        $email = $person['email'];
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

        $this->persons[$index]['tiers_id'] = $tiers->id;
        $this->persons[$index]['tiers_name'] = $tiers->displayName();
        $this->selectedTiers[$index] = $tiers->id;
    }

    public function render(): View
    {
        $linkedCount = collect($this->persons)->whereNotNull('tiers_id')->count();
        $unlinkedCount = collect($this->persons)->whereNull('tiers_id')->count();

        return view('livewire.parametres.helloasso-tiers-rapprochement', [
            'exercices' => app(ExerciceService::class)->available(5),
            'linkedCount' => $linkedCount,
            'unlinkedCount' => $unlinkedCount,
        ]);
    }
}
