@extends('layouts.public')

@section('title', __('Password-protected memory — :app', ['app' => config('app.name', 'Memoria')]))
@section('description', __('Enter the password for this private, unlisted memory.'))
@section('robots', 'noindex,nofollow,noarchive')

@section('content')
    <section class="mx-auto flex w-full max-w-3xl flex-1 items-center px-5 py-14 sm:px-8 sm:py-20" aria-labelledby="share-password-title">
        <div class="paper-surface mx-auto w-full max-w-lg p-6 sm:p-9">
            <x-public.status-badge status="shared" :label="__('Password protected')" />
            <h1 id="share-password-title" class="editorial-title mt-6 text-3xl">{{ __('Enter the sharing password') }}</h1>
            <p class="muted-copy mt-3 leading-7">{{ __('This unlisted memory is protected by a password chosen by its owner.') }}</p>
            <form method="POST" action="{{ route('shares.show', ['token' => $token]) }}" class="mt-7 grid gap-5">
                @csrf
                <div>
                    <label for="share-password" class="text-sm font-semibold">{{ __('Password') }}</label>
                    <input id="share-password" name="password" type="password" required autofocus autocomplete="current-password" class="form-field mt-2" @error('password') aria-describedby="share-password-error" aria-invalid="true" @enderror>
                    @error('password')
                        <p id="share-password-error" class="mt-2 text-sm text-red-700 dark:text-red-300">{{ $message }}</p>
                    @enderror
                </div>
                <button type="submit" class="button-primary">{{ __('Open shared memory') }}</button>
            </form>
        </div>
    </section>
@endsection
