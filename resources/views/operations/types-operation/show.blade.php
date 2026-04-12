<x-app-layout>
    <x-slot:title>{{ isset($typeOperation) ? $typeOperation->nom : 'Nouveau type d\'opération' }}</x-slot:title>
    <x-slot:breadcrumbParent url="{{ route('types-operation.index') }}">Types d'opération</x-slot:breadcrumbParent>

    @if(isset($typeOperation))
        <livewire:type-operation-show :type-operation="$typeOperation" />
    @else
        <livewire:type-operation-show />
    @endif
</x-app-layout>
