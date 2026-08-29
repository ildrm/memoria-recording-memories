<?php

namespace App\Services;

use App\Enums\ReminderFrequency;
use App\Models\Reminder;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

class ReminderSchedule
{
    public function __construct(private readonly LocalDateTimeResolver $localDateTimeResolver) {}

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function prepareFormData(array $data): array
    {
        $frequency = ReminderFrequency::from((string) $data['frequency']);
        $data['local_time'] = $this->wallTimeFromApplicationTime(
            (string) $data['local_time'],
            (string) $data['timezone'],
        );
        $data['day_of_week'] = $frequency === ReminderFrequency::Weekly
            ? (int) $data['day_of_week']
            : null;
        $data['day_of_month'] = $frequency === ReminderFrequency::Monthly
            ? (int) $data['day_of_month']
            : null;
        $data['interval_days'] = $frequency === ReminderFrequency::Custom
            ? (int) $data['interval_days']
            : null;

        $reminder = new Reminder;
        $reminder->forceFill($data);
        $data['next_run_at'] = (bool) ($data['is_enabled'] ?? false)
            ? $this->initial($reminder)
            : null;

        return $data;
    }

    /**
     * Filament's date-time state cast expects persisted values in the application timezone.
     * Reminder times are deliberately persisted as recurring local wall times instead.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function prepareFormDataForFill(array $data): array
    {
        if (! is_string($data['local_time'] ?? null) || ! is_string($data['timezone'] ?? null)) {
            return $data;
        }

        [$hour, $minute] = $this->timeParts($data['local_time']);
        $localDate = CarbonImmutable::now($data['timezone']);
        $data['local_time'] = $this->atLocalTime($localDate, $hour, $minute)
            ->setTimezone((string) config('app.timezone', 'UTC'))
            ->format('H:i:s');

        return $data;
    }

    public function initial(Reminder $reminder, ?CarbonImmutable $now = null): CarbonImmutable
    {
        $now ??= CarbonImmutable::now('UTC');

        return $this->nextCandidate($reminder, $now, true);
    }

    public function following(Reminder $reminder, ?CarbonImmutable $now = null): CarbonImmutable
    {
        $now ??= CarbonImmutable::now('UTC');

        return $this->nextCandidate($reminder, $now, false);
    }

    private function nextCandidate(Reminder $reminder, CarbonImmutable $now, bool $initial): CarbonImmutable
    {
        $timezone = trim((string) $reminder->timezone);
        $localNow = $now->setTimezone($timezone);
        [$hour, $minute] = $this->localTime($reminder);
        $frequency = $reminder->frequency instanceof ReminderFrequency
            ? $reminder->frequency
            : ReminderFrequency::from((string) $reminder->frequency);

        $candidate = match ($frequency) {
            ReminderFrequency::Daily => $this->daily($localNow, $hour, $minute),
            ReminderFrequency::Weekly => $this->weekly($reminder, $localNow, $hour, $minute),
            ReminderFrequency::Monthly => $this->monthly($reminder, $localNow, $hour, $minute),
            ReminderFrequency::Custom => $initial
                ? $this->daily($localNow, $hour, $minute)
                : $this->custom($reminder, $localNow, $hour, $minute),
        };

        return $candidate->utc();
    }

    private function daily(CarbonImmutable $localNow, int $hour, int $minute): CarbonImmutable
    {
        $candidate = $this->atLocalTime($localNow, $hour, $minute);

        return $candidate->isAfter($localNow)
            ? $candidate
            : $this->atLocalTime($localNow->addDay(), $hour, $minute);
    }

    private function weekly(
        Reminder $reminder,
        CarbonImmutable $localNow,
        int $hour,
        int $minute,
    ): CarbonImmutable {
        $dayOfWeek = (int) $reminder->day_of_week;
        $dayOfWeek = $dayOfWeek === 0 ? 7 : $dayOfWeek;
        if ($dayOfWeek < 1 || $dayOfWeek > 7) {
            throw new InvalidArgumentException('A weekly reminder requires a valid day of week.');
        }

        $daysUntil = ($dayOfWeek - $localNow->isoWeekday() + 7) % 7;
        $candidate = $this->atLocalTime($localNow->addDays($daysUntil), $hour, $minute);

        return $candidate->isAfter($localNow)
            ? $candidate
            : $this->atLocalTime($candidate->addWeek(), $hour, $minute);
    }

    private function monthly(
        Reminder $reminder,
        CarbonImmutable $localNow,
        int $hour,
        int $minute,
    ): CarbonImmutable {
        $dayOfMonth = (int) $reminder->day_of_month;
        if ($dayOfMonth < 1 || $dayOfMonth > 31) {
            throw new InvalidArgumentException('A monthly reminder requires a valid day of month.');
        }

        $candidateMonth = $localNow->startOfMonth();
        $candidate = $this->atLocalTime(
            $candidateMonth->day(min($dayOfMonth, $candidateMonth->daysInMonth)),
            $hour,
            $minute,
        );
        if ($candidate->isAfter($localNow)) {
            return $candidate;
        }

        $candidateMonth = $candidateMonth->addMonth();

        return $this->atLocalTime(
            $candidateMonth->day(min($dayOfMonth, $candidateMonth->daysInMonth)),
            $hour,
            $minute,
        );
    }

    private function custom(
        Reminder $reminder,
        CarbonImmutable $localNow,
        int $hour,
        int $minute,
    ): CarbonImmutable {
        $intervalDays = (int) $reminder->interval_days;
        if ($intervalDays < 2 || $intervalDays > 365) {
            throw new InvalidArgumentException('A custom reminder requires an interval between 2 and 365 days.');
        }

        return $this->atLocalTime($localNow->addDays($intervalDays), $hour, $minute);
    }

    /** @return array{0: int, 1: int} */
    private function localTime(Reminder $reminder): array
    {
        return $this->timeParts((string) $reminder->local_time);
    }

    /** @return array{0: int, 1: int} */
    private function timeParts(string $localTime): array
    {
        if (preg_match('/\A([01][0-9]|2[0-3]):([0-5][0-9])(?::[0-5][0-9])?\z/', $localTime, $matches) !== 1) {
            throw new InvalidArgumentException('A reminder requires a valid local time.');
        }

        return [(int) $matches[1], (int) $matches[2]];
    }

    private function wallTimeFromApplicationTime(string $applicationTime, string $timezone): string
    {
        [$hour, $minute] = $this->timeParts($applicationTime);
        $applicationDate = CarbonImmutable::now((string) config('app.timezone', 'UTC'));

        return $this->atLocalTime($applicationDate, $hour, $minute)
            ->setTimezone($timezone)
            ->format('H:i:s');
    }

    private function atLocalTime(CarbonImmutable $date, int $hour, int $minute): CarbonImmutable
    {
        $timezone = $date->getTimezone()->getName();
        $wallTime = sprintf(
            '%04d-%02d-%02d %02d:%02d:00',
            $date->year,
            $date->month,
            $date->day,
            $hour,
            $minute,
        );

        return $this->localDateTimeResolver
            ->resolveRecurring($wallTime, $timezone)
            ->setTimezone($timezone);
    }
}
