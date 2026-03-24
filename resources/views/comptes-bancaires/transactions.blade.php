<x-app-layout>
    <div class="container-fluid py-3">
        <div class="mb-2">
            <a href="{{ route($espace->value . '.parametres.comptes-bancaires.index') }}" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-arrow-left"></i> Comptes
            </a>
        </div>
        <livewire:transaction-universelle
            :compte-id="$compte->id"
            :page-title="'Transactions — '.$compte->nom"
            page-title-icon="bank" />
    </div>
</x-app-layout>
