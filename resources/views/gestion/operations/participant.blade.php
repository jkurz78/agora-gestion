<x-app-layout>
    <x-slot:title>{{ $participant->tiers?->displayName() ?? 'Participant' }}</x-slot:title>
    <x-slot:breadcrumbGrandParent url="{{ route('operations.index') }}">Liste des opérations</x-slot:breadcrumbGrandParent>
    <x-slot:breadcrumbParent url="{{ route('operations.show', $operation) }}">{{ $operation->nom }}</x-slot:breadcrumbParent>
    <livewire:participant-show :operation="$operation" :participant="$participant" />
</x-app-layout>
