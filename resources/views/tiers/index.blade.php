<x-app-layout>
    <x-slot name="header">
        <h2 class="fw-semibold fs-5 text-dark">Tiers</h2>
    </x-slot>

    <div class="container py-4">
        <div class="d-flex gap-2 mb-3 align-items-center">
            <button class="btn text-white" style="background:#722281"
                    onclick="Livewire.dispatch('open-tiers-form', {prefill: {}})">
                + Nouveau tiers
            </button>
            @if(auth()->user()->role->canWrite(\App\Enums\Espace::Compta) || auth()->user()->role->canWrite(\App\Enums\Espace::Gestion))
                @livewire('import-csv-tiers')
            @endif
            <div class="dropdown">
                <button class="btn btn-outline-secondary btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown">
                    <i class="bi bi-download"></i> Exporter
                </button>
                <ul class="dropdown-menu">
                    <li><a class="dropdown-item" href="{{ route('compta.tiers.export', ['format' => 'xlsx']) }}"><i class="bi bi-file-earmark-excel me-1"></i> Excel (.xlsx)</a></li>
                    <li><a class="dropdown-item" href="{{ route('compta.tiers.export', ['format' => 'csv']) }}"><i class="bi bi-file-earmark-text me-1"></i> CSV</a></li>
                </ul>
            </div>
        </div>
        @livewire('tiers-list')
    </div>
</x-app-layout>
