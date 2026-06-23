<x-app-layout>
    <x-slot:title>Résultats — {{ $campagne->titre_affiche }}</x-slot:title>

    <livewire:questionnaire.campagne-resultats :campagne="$campagne" />
</x-app-layout>
