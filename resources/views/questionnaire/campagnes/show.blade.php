<x-app-layout>
    <x-slot:breadcrumbGreatGrandParent url="{{ route('operations.index') }}">Liste des opérations</x-slot:breadcrumbGreatGrandParent>
    <x-slot:breadcrumbGrandParent url="{{ route('operations.show', $campagne->operation_id) }}?tab=questionnaires">{{ $campagne->operation->nom }}</x-slot:breadcrumbGrandParent>
    <x-slot:title>{{ $campagne->titre_affiche ?: $campagne->titre }}</x-slot:title>
    <livewire:questionnaire.campagne-show :campagne="$campagne" :key="'fiche-'.$campagne->id" />
</x-app-layout>
