<?php

namespace Tests\Feature;

use Tests\TestCase;

class PublicLegalRoutesTest extends TestCase
{
    public function test_public_landing_and_operator_review_legal_templates_are_reachable(): void
    {
        $this->get(route('home'))
            ->assertOk()
            ->assertSee(route('privacy'), false)
            ->assertSee(route('terms'), false);

        foreach (['privacy', 'terms'] as $routeName) {
            $this->get(route($routeName))
                ->assertOk()
                ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')
                ->assertSee('operator', escape: false)
                ->assertSee('noindex,nofollow,noarchive', escape: false);
        }
    }

    public function test_non_production_robots_disallow_the_entire_site(): void
    {
        $this->assertFileDoesNotExist(public_path('robots.txt'));

        $this->get(route('robots'))
            ->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->assertSee("User-agent: *\nDisallow: /", escape: false)
            ->assertDontSee('Sitemap:', escape: false);
    }

    public function test_dynamic_code_is_allowed_only_on_the_reactive_panels(): void
    {
        $publicResponse = $this->get(route('home'));
        $publicResponse->assertOk();
        $this->assertStringNotContainsString(
            'unsafe-eval',
            (string) $publicResponse->headers->get('Content-Security-Policy'),
        );
        $this->assertStringContainsString(
            "img-src 'self' data:",
            (string) $publicResponse->headers->get('Content-Security-Policy'),
        );
        $this->assertStringNotContainsString(
            "img-src 'self' data: https:",
            (string) $publicResponse->headers->get('Content-Security-Policy'),
        );

        $panelResponse = $this->get('/app/login');
        $panelResponse->assertOk();
        $this->assertStringContainsString(
            "script-src 'self' 'unsafe-inline' 'unsafe-eval'",
            (string) $panelResponse->headers->get('Content-Security-Policy'),
        );
    }
}
