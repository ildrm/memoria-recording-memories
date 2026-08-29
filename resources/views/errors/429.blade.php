@extends('errors.layout')

@section('code', '429')
@section('message', __('Please slow down for a moment'))
@section('description', __('Too many requests arrived at once. Wait briefly, then try again.'))

