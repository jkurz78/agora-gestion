<x-app-layout>
    <h1 class="mb-4">Rapports</h1>

    <ul class="nav nav-tabs mb-4" id="rapportsTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="compte-resultat-tab" data-bs-toggle="tab"
                    data-bs-target="#compte-resultat" type="button" role="tab"
                    aria-controls="compte-resultat" aria-selected="true">
                Compte de résultat
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="rapport-seances-tab" data-bs-toggle="tab"
                    data-bs-target="#rapport-seances" type="button" role="tab"
                    aria-controls="rapport-seances" aria-selected="false">
                Rapport par séances
            </button>
        </li>
    </ul>

    <div class="tab-content" id="rapportsTabContent">
        <div class="tab-pane fade show active" id="compte-resultat" role="tabpanel"
             aria-labelledby="compte-resultat-tab">
            <livewire:rapport-compte-resultat />
        </div>
        <div class="tab-pane fade" id="rapport-seances" role="tabpanel"
             aria-labelledby="rapport-seances-tab">
            <livewire:rapport-seances />
        </div>
    </div>
</x-app-layout>
