<x-app-layout>
    @php $campagne = $scan->campaign; @endphp
    <x-slot:breadcrumbGrandParent url="{{ route('operations.show', $campagne->operation_id) }}#questionnaires">{{ $campagne->operation->nom }}</x-slot:breadcrumbGrandParent>
    <x-slot:breadcrumbParent url="{{ route('questionnaires.campagnes.scans', $campagne) }}">Scans — {{ $campagne->titre_affiche ?: $campagne->titre }}</x-slot:breadcrumbParent>
    <x-slot:title>Validation OCR</x-slot:title>
    <livewire:questionnaire.assistant-saisie :scan="$scan" />
</x-app-layout>
