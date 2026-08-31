@extends('errors.layout')

@section('code', '422')
@section('message', __('This connection is not available'))

@section('description')
    {{ $description }}
@endsection
