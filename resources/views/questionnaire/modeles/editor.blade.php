<x-app-layout>
    <x-slot:breadcrumbParent url="{{ route('questionnaires.modeles.index') }}">Modèles de questionnaires</x-slot:breadcrumbParent>
    <x-slot:title>{{ $template->titre_interne }}</x-slot:title>

    <livewire:questionnaire.modele-editor :template="$template" />
</x-app-layout>
