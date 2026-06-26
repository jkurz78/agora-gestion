<x-app-layout>
    <x-slot:title>Envoyer les invitations</x-slot:title>
    <div class="mb-3">
        <a href="{{ route('operations.show', $campagne->operation_id) }}" class="btn btn-sm btn-link px-0">&larr; Retour à l'opération</a>
        <h1 class="h4 mb-0">Envoyer — {{ $campagne->titre_affiche }}</h1>
    </div>
    <livewire:questionnaire.envoi-compose :campagne="$campagne" :key="'envoi-'.$campagne->id" />
</x-app-layout>
