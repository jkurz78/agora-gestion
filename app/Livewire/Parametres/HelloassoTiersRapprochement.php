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
     * @var list<array{firstName: string, lastName: string, email: ?string, address: ?string, city: ?string, zipCode: ?string, country: ?string, tiers_id: ?int, tiers_name: ?string}>
     */
    public array $persons = [];

    /** @var array<int, ?int> index → selected tiers_id */
    public array $selectedTiers = [];

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

        // Map extracted persons by key for payer data lookup
        $personDataByKey = [];
        foreach ($extractedPersons as $p) {
            $key = strtolower($p['lastName']) . '|' . strtolower($p['firstName']);
            $personDataByKey[$key] = $p;
        }

        // Unlinked persons (sorted first)
        foreach ($result['unlinked'] as $person) {
            $suggestedId = null;
            if (count($person['suggestions']) > 0) {
                $suggestedId = $person['suggestions'][0]['tiers_id'];
            }

            $key = strtolower($person['lastName']) . '|' . strtolower($person['firstName']);
            $data = $personDataByKey[$key] ?? [];

            $this->persons[] = [
                'firstName' => $person['firstName'],
                'lastName' => $person['lastName'],
                'email' => $person['email'] ?? null,
                'address' => $data['address'] ?? null,
                'city' => $data['city'] ?? null,
                'zipCode' => $data['zipCode'] ?? null,
                'country' => $data['country'] ?? null,
                'tiers_id' => null,
                'tiers_name' => null,
            ];
            $this->selectedTiers[$index] = $suggestedId;
            $index++;
        }

        // Linked persons (after unlinked)
        foreach ($result['linked'] as $person) {
            $this->persons[] = [
                'firstName' => $person['firstName'],
                'lastName' => $person['lastName'],
                'email' => $person['email'] ?? null,
                'address' => null,
                'city' => null,
                'zipCode' => null,
                'country' => null,
                'tiers_id' => $person['tiers_id'],
                'tiers_name' => $person['tiers_name'],
            ];
            $this->selectedTiers[$index] = $person['tiers_id'];
            $index++;
        }

        $this->fetched = true;
    }

    public function associer(int $index): void
    {
        $tiersId = $this->selectedTiers[$index] ?? null;
        $person = $this->persons[$index] ?? null;
        if ($tiersId === null || $person === null) {
            return;
        }

        $tiers = Tiers::findOrFail($tiersId);

        $tiers->update([
            'est_helloasso' => true,
            'helloasso_nom' => $person['lastName'],
            'helloasso_prenom' => $person['firstName'],
        ]);

        $this->persons[$index]['tiers_id'] = $tiers->id;
        $this->persons[$index]['tiers_name'] = $tiers->displayName();
    }

    public function creer(int $index): void
    {
        $person = $this->persons[$index] ?? null;
        if ($person === null) {
            return;
        }

        $tiers = Tiers::create([
            'type' => 'particulier',
            'nom' => $person['lastName'],
            'prenom' => $person['firstName'],
            'email' => $person['email'],
            'adresse_ligne1' => $person['address'],
            'ville' => $person['city'],
            'code_postal' => $person['zipCode'],
            'pays' => $person['country'],
            'est_helloasso' => true,
            'helloasso_nom' => $person['lastName'],
            'helloasso_prenom' => $person['firstName'],
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
