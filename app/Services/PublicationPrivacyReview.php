<?php

namespace App\Services;

use App\Models\Entry;
use App\Models\Publication;
use App\Models\PublicationMedia;
use Illuminate\Support\Str;

class PublicationPrivacyReview
{
    /**
     * Heuristic warnings support deliberate review; they do not certify that content is safe.
     *
     * @return array<int, array{code: string, message: string}>
     */
    public function warnings(Publication $publication): array
    {
        $warnings = [];
        $text = trim($publication->title."\n".$publication->excerpt."\n".$publication->body);

        if (preg_match('/\b[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}\b/iu', $text) === 1) {
            $warnings[] = $this->warning('contact_email', 'The public draft may contain an email address.');
        }

        if (preg_match('/(?<!\d)(?:\+?\d[\d .()\-]{7,}\d)(?!\d)/u', $text) === 1) {
            $warnings[] = $this->warning('contact_phone', 'The public draft may contain a phone number.');
        }

        if (preg_match('/\b(?:street|st\.|avenue|ave\.|road|rd\.|boulevard|blvd\.|postal|postcode|zip)\b/iu', $text) === 1) {
            $warnings[] = $this->warning('address', 'The public draft may contain a street or postal address.');
        }

        $sourceEntry = Entry::withTrashed()
            ->where('user_id', $publication->user_id)
            ->find($publication->source_entry_id);
        if ($sourceEntry !== null && (
            filled($sourceEntry->location_name)
            || $sourceEntry->latitude !== null
            || $sourceEntry->longitude !== null
        )) {
            $warnings[] = $this->warning('location', 'The source memory contains location information. Confirm it is not present in the public version.');
        }

        if ($sourceEntry !== null && $sourceEntry->people()->exists()) {
            $warnings[] = $this->warning('people', 'The source memory names or references people. Confirm they consent to any public details.');
        }

        if (PublicationMedia::query()
            ->whereBelongsTo($publication)
            ->whereNotNull('source_attachment_id')
            ->exists()) {
            $warnings[] = $this->warning('media', 'Public media was copied from a private attachment. Verify metadata, framing, and visible details.');
        }

        if (Str::contains(Str::lower($text), ['latitude', 'longitude', 'gps'])) {
            $warnings[] = $this->warning('coordinates', 'The public draft may mention precise coordinates or GPS data.');
        }

        return $warnings;
    }

    /**
     * @return array{code: string, message: string}
     */
    private function warning(string $code, string $message): array
    {
        return ['code' => $code, 'message' => $message];
    }
}
