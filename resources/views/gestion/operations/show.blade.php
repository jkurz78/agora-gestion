<x-app-layout>
    <x-slot:title>{{ $operation->nom }}</x-slot:title>
    <x-slot:breadcrumbParent url="{{ route('gestion.operations') }}">Liste des opérations</x-slot:breadcrumbParent>
    <livewire:operation-detail :operation="$operation" />
</x-app-layout>
