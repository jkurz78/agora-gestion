<x-app-layout>
    <x-slot:breadcrumbParent url="{{ route('questionnaires.modeles.index') }}">Modèles de questionnaires</x-slot:breadcrumbParent>
    <x-slot:title>{{ $template->titre_interne }} — Textes</x-slot:title>

    <livewire:questionnaire.modele-textes :template="$template" />
</x-app-layout>
