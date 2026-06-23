<x-app-layout>
    <x-slot:title>{{ $operation->nom }}</x-slot:title>
    <x-slot:breadcrumbParent url="{{ route('operations.index') }}">Liste des opérations</x-slot:breadcrumbParent>
    <livewire:operation-detail :operation="$operation" />

    <section class="mt-4">
        @livewire('questionnaire.operation-questionnaires', ['operation' => $operation])
    </section>
</x-app-layout>
