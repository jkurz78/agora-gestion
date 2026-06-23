<x-app-layout>
    <x-slot:title>{{ $template->titre_interne }}</x-slot:title>

    <livewire:questionnaire.modele-editor :template="$template" />
</x-app-layout>
