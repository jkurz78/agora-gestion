@extends('layouts.app')

@section('content')
    <div class="container-fluid py-3">
        @livewire('questionnaire.modele-editor', ['template' => $template])
    </div>
@endsection
