<x-app-layout>
    <x-slot:breadcrumbGrandParent url="{{ route('operations.index') }}">Liste des opérations</x-slot:breadcrumbGrandParent>
    <x-slot:breadcrumbParent url="{{ route('operations.show', $campagne->operation_id) }}#questionnaires">{{ $campagne->operation->nom }}</x-slot:breadcrumbParent>
    <x-slot:title>Scans — {{ $campagne->titre_affiche ?: $campagne->titre }}</x-slot:title>
    <livewire:questionnaire.scan-upload :campagne="$campagne" />
</x-app-layout>
