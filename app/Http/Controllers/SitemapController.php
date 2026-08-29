<?php

namespace App\Http\Controllers;

use App\Models\Publication;
use App\Services\PublicWebsitePublicationQuery;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

class SitemapController extends Controller
{
    public function __construct(
        private readonly PublicWebsitePublicationQuery $websitePublications,
    ) {}

    public function index(): Response
    {
        return $this->cachedResponse('memoria:sitemap:v4:index', function (): string {
            $pageSize = $this->pageSize();
            $publicationCount = $this->indexablePublications()->count('publications.id');
            $publicationPages = (int) ceil($publicationCount / $pageSize);
            $body = '<?xml version="1.0" encoding="UTF-8"?><sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';

            $body .= $this->sitemap(route('sitemaps.static'));

            for ($page = 1; $page <= $publicationPages; $page++) {
                $body .= $this->sitemap(route('sitemaps.publications', ['page' => $page]));
            }

            return $body.'</sitemapindex>';
        });
    }

    public function staticPages(): Response
    {
        return $this->cachedResponse(
            'memoria:sitemap:v4:static',
            fn (): string => '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
                .$this->url(route('home'))
                .'</urlset>',
        );
    }

    public function publications(string $page): Response
    {
        $pageNumber = (int) $page;

        return $this->cachedResponse(
            'memoria:sitemap:v4:publications:'.$this->pageSize().':'.$pageNumber,
            function () use ($pageNumber): string {
                $body = '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
                $count = 0;

                $this->indexablePublications()
                    ->select([
                        'publications.id', 'publications.user_id', 'publications.slug',
                        'publications.updated_at', 'user_profiles.username as owner_username',
                    ])
                    ->orderBy('publications.id')
                    ->forPage($pageNumber, $this->pageSize())
                    ->cursor()
                    ->each(function (Publication $publication) use (&$body, &$count): void {
                        $body .= $this->url(
                            route('publications.show', [
                                (string) $publication->getAttribute('owner_username'),
                                $publication->slug,
                            ]),
                            $publication->updated_at,
                        );
                        $count++;
                    });

                abort_if($count === 0, 404);

                return $body.'</urlset>';
            },
        );
    }

    /** @param Closure(): string $build */
    private function cachedResponse(string $cacheKey, Closure $build): Response
    {
        $cached = Cache::get($cacheKey);
        if (is_string($cached)) {
            return $this->response($cached);
        }

        try {
            $body = Cache::lock(
                'memoria:sitemap:build:'.hash('sha256', $cacheKey),
                max(5, (int) config('memoria.sitemap.lock_seconds', 30)),
            )->block(3, function () use ($cacheKey, $build): string {
                $cached = Cache::get($cacheKey);
                if (is_string($cached)) {
                    return $cached;
                }

                $body = $build();
                Cache::put(
                    $cacheKey,
                    $body,
                    now()->addSeconds(max(60, (int) config('memoria.sitemap.cache_seconds', 900))),
                );

                return $body;
            });
        } catch (LockTimeoutException) {
            return response(
                'Sitemap generation is temporarily busy.',
                503,
                ['Content-Type' => 'text/plain; charset=UTF-8', 'Retry-After' => '5'],
            );
        }

        return $this->response($body);
    }

    /** @return Builder<Publication> */
    private function indexablePublications(): Builder
    {
        return $this->websitePublications
            ->query()
            ->where('search_engine_indexing', true)
            ->join('user_profiles', 'user_profiles.user_id', '=', 'publications.user_id')
            ->join('users', 'users.id', '=', 'publications.user_id')
            ->where('user_profiles.is_public', true)
            ->whereNotNull('user_profiles.username')
            ->whereNull('users.disabled_at');
    }

    private function pageSize(): int
    {
        return max(1, min(50000, (int) config('memoria.sitemap.maximum_urls', 5000)));
    }

    private function response(string $body): Response
    {
        return response($body, 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Cache-Control' => 'public, max-age=300',
        ]);
    }

    private function sitemap(string $location): string
    {
        return '<sitemap><loc>'.$this->xml($location).'</loc></sitemap>';
    }

    private function url(string $location, mixed $lastModified = null): string
    {
        $lastModifiedElement = $lastModified === null
            ? ''
            : '<lastmod>'.CarbonImmutable::parse($lastModified)->toAtomString().'</lastmod>';

        return '<url><loc>'.$this->xml($location).'</loc>'.$lastModifiedElement.'</url>';
    }

    private function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
