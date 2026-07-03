<x-app-layout>
    <x-slot:breadcrumbGrandParent url="{{ route('operations.index') }}">Liste des opérations</x-slot:breadcrumbGrandParent>
    <x-slot:breadcrumbParent url="{{ route('operations.show', $campagne->operation_id) }}#questionnaires">{{ $campagne->operation->nom }}</x-slot:breadcrumbParent>
    <x-slot:title>Invitations — {{ $campagne->titre_affiche }}</x-slot:title>
    <livewire:questionnaire.envoi-compose :campagne="$campagne" :key="'envoi-'.$campagne->id" />
</x-app-layout>
