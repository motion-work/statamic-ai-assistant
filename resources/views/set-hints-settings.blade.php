@extends('statamic::layout')

@section('title', __('BOLD agent settings'))

@section('content')
    @if(session('success'))
        <div class="hidden" data-figma-flash-success>{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="hidden" data-figma-flash-error>{{ session('error') }}</div>
    @endif
    <set-hints-settings-page></set-hints-settings-page>
@endsection
