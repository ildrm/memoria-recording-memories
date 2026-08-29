@extends('layouts.public')

@section('title', trim($__env->yieldContent('code')) . ' — ' . trim($__env->yieldContent('message')))
@section('robots', 'noindex,nofollow')

@section('content')
    <section class="mx-auto flex min-h-[65svh] w-full max-w-3xl items-center px-5 py-16 sm:px-8" aria-labelledby="error-title">
        <div class="w-full text-center">
            <p class="font-mono text-sm font-bold tracking-[0.16em] text-[var(--ribbon)]">@yield('code')</p>
            <h1 id="error-title" class="editorial-title mt-4 text-4xl sm:text-6xl">@yield('message')</h1>
            <p class="muted-copy mx-auto mt-5 max-w-xl leading-7">@yield('description')</p>
            <div class="mt-8 flex flex-col justify-center gap-3 sm:flex-row">
                <a href="{{ url()->previous() !== url()->current() ? url()->previous() : url('/') }}" class="button-secondary">{{ __('Go back') }}</a>
                <a href="{{ url('/') }}" class="button-primary">{{ __('Return home') }}</a>
            </div>
        </div>
    </section>
@endsection

