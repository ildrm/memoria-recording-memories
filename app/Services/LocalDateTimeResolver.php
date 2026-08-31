<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Validation\ValidationException;
use Throwable;

class LocalDateTimeResolver
{
    public function resolve(
        DateTimeInterface|string $value,
        string $timezone,
        string $field = 'scheduled_at',
    ): CarbonImmutable {
        $timezoneObject = $this->timezone($timezone);

        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value)->utc();
        }

        $value = trim($value);

        if (preg_match('/(?:Z|[+-]\d{2}:?\d{2})$/i', $value) === 1) {
            try {
                return CarbonImmutable::parse($value)->utc();
            } catch (Throwable) {
                throw $this->invalidDateTime($field);
            }
        }

        $normalized = $this->normalizeWallTime($value, $field);
        $candidates = $this->candidates($normalized, $timezoneObject);

        if ($candidates === []) {
            throw ValidationException::withMessages([
                $field => [__('That local time does not exist because the clock moves forward. Choose another time.')],
            ]);
        }

        if (count($candidates) > 1) {
            throw ValidationException::withMessages([
                $field => [__('That local time occurs twice because the clock moves back. Choose another time or include an explicit UTC offset.')],
            ]);
        }

        return array_values($candidates)[0]->utc();
    }

    public function resolveRecurring(string $value, string $timezone): CarbonImmutable
    {
        $timezoneObject = $this->timezone($timezone);
        $normalized = $this->normalizeWallTime(trim($value), 'local_time');
        $candidates = $this->candidates($normalized, $timezoneObject);

        if ($candidates !== []) {
            ksort($candidates);

            return array_values($candidates)[0]->utc();
        }

        $normalizedCandidate = CarbonImmutable::createFromFormat(
            '!Y-m-d H:i:s',
            $normalized,
            $timezoneObject,
        );

        if ($normalizedCandidate === null) {
            throw $this->invalidDateTime('local_time');
        }

        return $normalizedCandidate->utc();
    }

    private function timezone(string $timezone): DateTimeZone
    {
        try {
            return new DateTimeZone($timezone);
        } catch (Throwable) {
            throw ValidationException::withMessages([
                'timezone' => [__('Choose a valid timezone.')],
            ]);
        }
    }

    private function normalizeWallTime(string $value, string $field): string
    {
        if (preg_match('/\A\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(?::\d{2})?\z/', $value) !== 1) {
            throw $this->invalidDateTime($field);
        }

        $normalized = str_replace('T', ' ', $value);
        if (strlen($normalized) === 16) {
            $normalized .= ':00';
        }

        $wallTime = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $normalized, new DateTimeZone('UTC'));
        if ($wallTime === false || $wallTime->format('Y-m-d H:i:s') !== $normalized) {
            throw $this->invalidDateTime($field);
        }

        return $normalized;
    }

    /** @return array<int, CarbonImmutable> */
    private function candidates(string $normalized, DateTimeZone $timezone): array
    {
        $wallTime = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $normalized, new DateTimeZone('UTC'));
        if ($wallTime === false) {
            return [];
        }

        $wallTimestamp = $wallTime->getTimestamp();
        $offsets = [$timezone->getOffset($wallTime)];
        $transitions = $timezone->getTransitions($wallTimestamp - 172800, $wallTimestamp + 172800);

        if (is_array($transitions)) {
            foreach ($transitions as $transition) {
                $offsets[] = $transition['offset'];
            }
        }

        $candidates = [];

        foreach (array_unique($offsets) as $offset) {
            $candidate = CarbonImmutable::createFromTimestampUTC($wallTimestamp - $offset);

            if ($candidate->setTimezone($timezone)->format('Y-m-d H:i:s') === $normalized) {
                $candidates[$candidate->getTimestamp()] = $candidate;
            }
        }

        return $candidates;
    }

    private function invalidDateTime(string $field): ValidationException
    {
        return ValidationException::withMessages([
            $field => [__('Choose a valid local date and time.')],
        ]);
    }
}
