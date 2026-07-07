<x-app-layout>
    @php $campagne = $scan->campaign; @endphp
    <x-slot:breadcrumbGreatGrandParent url="{{ route('operations.show', $campagne->operation_id) }}?tab=questionnaires">{{ $campagne->operation->nom }}</x-slot:breadcrumbGreatGrandParent>
    <x-slot:breadcrumbGrandParent url="{{ route('questionnaires.campagnes.show', $campagne) }}">{{ $campagne->titre_affiche ?: $campagne->titre }}</x-slot:breadcrumbGrandParent>
    <x-slot:breadcrumbParent url="{{ route('questionnaires.campagnes.show', ['campagne' => $campagne, 'tab' => 'scans']) }}">Scans</x-slot:breadcrumbParent>
    <x-slot:title>Validation OCR</x-slot:title>
    <livewire:questionnaire.assistant-saisie :scan="$scan" />
</x-app-layout>
