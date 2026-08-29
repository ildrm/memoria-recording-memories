@props(['class' => 'size-8'])

<svg {{ $attributes->class([$class]) }} viewBox="0 0 32 32" fill="none" aria-hidden="true">
    <path d="M7 5.5h14.5A3.5 3.5 0 0 1 25 9v17.5H10.5A3.5 3.5 0 0 1 7 23V5.5Z" fill="currentColor" class="text-sage-700 dark:text-sage-300" />
    <path d="M10 5.5h12A3 3 0 0 1 25 8.5V26H13a3 3 0 0 1-3-3V5.5Z" fill="var(--surface)" stroke="currentColor" class="text-sage-700 dark:text-sage-300" stroke-width="1.25" />
    <path d="M14.25 10.5h6.5M14.25 14.5h5M14.25 18.5h6.5" stroke="currentColor" class="text-memory-500 dark:text-memory-300" stroke-linecap="round" stroke-width="1.5" />
</svg>

