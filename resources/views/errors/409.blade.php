@extends('errors::layout')

@section('title', __('405 Method Not Allowed'))
@section('code', '405')
@section('message', __('Method Not Allowed'))

@section('description')
    {{ __('The method specified in the request is not allowed for the resource identified by the request URI.') }}
@endsection

@section('suggestion')
    {{ __('Please verify the request method (GET, POST, etc.) and try again.') }}
@endsection

@section('action')
    {{ __('If you believe this is a mistake, please contact our support team.') }}
@endsection

@section('support')
    <div class="mt-4 text-sm text-gray-600">
        {{ __('Support Contact:') }}
        <a href="mailto:support@example.com" class="text-blue-600 underline">support@example.com</a><br>
        {{ __('Need further assistance? We’re here to help.') }}
    </div>
@endsection

@section('footer')
    {{ __('Thank you for your understanding.') }}
@endsection

@section('footer_note')
    {{ __('This page was automatically generated to handle HTTP 405 errors.') }}
@endsection

@section('footer_link')
    <a href="{{ url('/') }}"
       class="btn btn-primary flex items-center gap-2"
       target="_self"
       rel="noopener noreferrer">
        <i class="fa fa-home text-white"></i>
        {{ __('Go to Home Page') }}
    </a>
@endsection
