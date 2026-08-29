<?php

namespace App\Enums\Concerns;

trait HasOptions
{
    public function label(): string
    {
        return ucwords(str_replace(['-', '_'], ' ', $this->value));
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
