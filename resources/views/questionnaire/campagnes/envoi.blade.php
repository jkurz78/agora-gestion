<x-app-layout>
    <x-slot:breadcrumbGreatGrandParent url="{{ route('operations.index') }}">Liste des opérations</x-slot:breadcrumbGreatGrandParent>
    <x-slot:breadcrumbGrandParent url="{{ route('operations.show', $campagne->operation_id) }}?tab=questionnaires">{{ $campagne->operation->nom }}</x-slot:breadcrumbGrandParent>
    <x-slot:breadcrumbParent url="{{ route('questionnaires.campagnes.show', $campagne) }}">{{ $campagne->titre_affiche ?: $campagne->titre }}</x-slot:breadcrumbParent>
    <x-slot:title>Invitations</x-slot:title>
    <livewire:questionnaire.envoi-compose :campagne="$campagne" :key="'envoi-'.$campagne->id" />
</x-app-layout>
