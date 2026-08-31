<?php

namespace App\Http\Controllers;

use App\Services\ReviewedPublicUrl;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

class LegalDocumentController extends Controller
{
    public function __construct(private readonly ReviewedPublicUrl $reviewedPublicUrl) {}

    public function privacy(): RedirectResponse|View
    {
        return $this->document('privacy_notice_url', 'public.privacy');
    }

    public function terms(): RedirectResponse|View
    {
        return $this->document('terms_of_service_url', 'public.terms');
    }

    private function document(string $configurationKey, string $fallbackView): RedirectResponse|View
    {
        $configuredUrl = config("memoria.legal.{$configurationKey}");

        if (is_string($configuredUrl)
            && $this->reviewedPublicUrl->isValid($configuredUrl, true)
            && ! $this->redirectsToLocalLegalRoute($configuredUrl)
        ) {
            return redirect()->away($configuredUrl);
        }

        abort_if(
            app()->isProduction(),
            503,
            'A reviewed legal document has not been configured for this production deployment.',
        );

        return view($fallbackView);
    }

    private function redirectsToLocalLegalRoute(string $configuredUrl): bool
    {
        return $this->reviewedPublicUrl->areEquivalent($configuredUrl, route('privacy'), true)
            || $this->reviewedPublicUrl->areEquivalent($configuredUrl, route('terms'), true);
    }
}
