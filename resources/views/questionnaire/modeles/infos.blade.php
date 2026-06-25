<x-app-layout>
    <x-slot:title>{{ $template->titre_interne }} — Informations</x-slot:title>

    <livewire:questionnaire.modele-infos :template="$template" />
</x-app-layout>
