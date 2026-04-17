@extends('layouts.super-admin')

@section('content')
    @livewire(\App\Livewire\SuperAdmin\AssociationDetail::class, ['association' => $association])
@endsection
