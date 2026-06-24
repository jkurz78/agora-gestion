<x-app-layout>
    <x-slot:title>{{ $template->titre_interne }} — Textes</x-slot:title>

    <livewire:questionnaire.modele-textes :template="$template" />
</x-app-layout>
