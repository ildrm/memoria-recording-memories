@props([
    'media',
    'alt' => '',
    'preview' => false,
    'usage' => 'hero',
])

@php
    $variants = $media->responsiveImageVariants();
    $targetWidth = $usage === 'card' ? 480 : 960;
    $selectedVariant = collect($variants)->first(
        fn (array $variant): bool => $variant['width'] >= $targetWidth,
    ) ?? collect($variants)->last();
    $routeName = $preview ? 'publications.media.preview' : 'publications.media.show';
    $imageUrl = $selectedVariant
        ? route($routeName, ['publicationMedia' => $media, 'variant' => $selectedVariant['name']])
        : null;
    $sourceSet = collect($variants)
        ->map(fn (array $variant): string => route($routeName, [
            'publicationMedia' => $media,
            'variant' => $variant['name'],
        ]).' '.$variant['width'].'w')
        ->implode(', ');
    $sizes = $usage === 'card'
        ? '(min-width: 1280px) 36rem, (min-width: 768px) 50vw, calc(100vw - 2.5rem)'
        : '(min-width: 1280px) 68rem, calc(100vw - 2.5rem)';
    $protectedAttributes = [
        'alt', 'decoding', 'fetchpriority', 'height', 'loading', 'sizes', 'src', 'srcset', 'width',
    ];
@endphp

@if ($selectedVariant && $imageUrl)
    <img
        src="{{ $imageUrl }}"
        @if ($variants !== [])
            srcset="{{ $sourceSet }}"
            sizes="{{ $sizes }}"
        @endif
        alt="{{ $alt }}"
        width="{{ $selectedVariant['width'] }}"
        height="{{ $selectedVariant['height'] }}"
        loading="{{ $usage === 'card' ? 'lazy' : 'eager' }}"
        decoding="async"
        fetchpriority="{{ $usage === 'card' ? 'low' : 'high' }}"
        {{ $attributes->except($protectedAttributes) }}
    >
@endif
