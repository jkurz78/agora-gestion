<x-app-layout>
    <x-slot:title>Relevé du {{ $rapprochement->date_fin->format('d/m/Y') }}</x-slot:title>
    <x-slot:breadcrumbParent url="{{ route('banques.rapprochement.index') }}">Rapprochements bancaires</x-slot:breadcrumbParent>

    <livewire:rapprochement-detail :rapprochement="$rapprochement" />
</x-app-layout>
