<x-app-layout>
    <div class="container-fluid py-3">
        <div class="d-flex align-items-center gap-3 mb-3">
            <a href="{{ route('parametres.comptes-bancaires.index') }}" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-arrow-left"></i> Comptes
            </a>
            <h4 class="mb-0"><i class="bi bi-bank me-2"></i>Transactions — {{ $compte->nom }}</h4>
        </div>
        <livewire:transaction-universelle :compte-id="$compte->id" />
    </div>
</x-app-layout>
