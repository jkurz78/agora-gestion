<x-app-layout>
    <x-slot:breadcrumbParent url="{{ route('questionnaires.modeles.index') }}">Modèles de questionnaires</x-slot:breadcrumbParent>
    <x-slot:title>{{ $template->titre_interne }} — Informations</x-slot:title>

    <livewire:questionnaire.modele-infos :template="$template" />
</x-app-layout>
