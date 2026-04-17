@extends('layouts.onboarding')

@section('content')
    @livewire(\App\Livewire\Onboarding\Wizard::class)
@endsection
