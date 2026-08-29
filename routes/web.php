<?php

use App\Http\Controllers\AccountDeletionController;
use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\ConnectedSocialAccountController;
use App\Http\Controllers\EntryController;
use App\Http\Controllers\EntryPublicationController;
use App\Http\Controllers\EntryShareController;
use App\Http\Controllers\EntryVersionRestoreController;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\PublicationMediaPreviewController;
use App\Http\Controllers\PublicationPreviewController;
use App\Http\Controllers\PublicationPrivacyReviewController;
use App\Http\Controllers\PublicCommentController;
use App\Http\Controllers\PublicCommentDeletionController;
use App\Http\Controllers\PublicCommentRepliesController;
use App\Http\Controllers\PublicFeedController;
use App\Http\Controllers\PublicProfileController;
use App\Http\Controllers\PublicProfileImageController;
use App\Http\Controllers\PublicPublicationController;
use App\Http\Controllers\PublicPublicationMediaController;
use App\Http\Controllers\PublicReactionController;
use App\Http\Controllers\PublicReportController;
use App\Http\Controllers\PublishedPublicationController;
use App\Http\Controllers\RobotsController;
use App\Http\Controllers\ScheduledPublicationController;
use App\Http\Controllers\SharedAttachmentController;
use App\Http\Controllers\SharedContentController;
use App\Http\Controllers\SharedEntryController;
use App\Http\Controllers\ShareLinkController;
use App\Http\Controllers\SitemapController;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\NoIndex;
use App\Http\Middleware\PrivateResponse;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Support\Facades\Route;

Route::middleware(SecurityHeaders::class)->group(function (): void {
    Route::view('/', 'welcome')->name('home');
    Route::view('/privacy', 'public.privacy')
        ->middleware(NoIndex::class)
        ->name('privacy');
    Route::view('/terms', 'public.terms')
        ->middleware(NoIndex::class)
        ->name('terms');
    Route::get('/health', HealthController::class)
        ->middleware('throttle:public-read')
        ->name('health');
    Route::get('/robots.txt', RobotsController::class)->name('robots');
    Route::get('/sitemap.xml', [SitemapController::class, 'index'])
        ->middleware('throttle:sitemap')
        ->name('sitemap');
    Route::get('/sitemaps/static.xml', [SitemapController::class, 'staticPages'])
        ->middleware('throttle:sitemap')
        ->name('sitemaps.static');
    Route::get('/sitemaps/publications/{page}.xml', [SitemapController::class, 'publications'])
        ->where('page', '[1-9][0-9]*')
        ->middleware('throttle:sitemap')
        ->name('sitemaps.publications');

    Route::middleware('throttle:public-read')->group(function (): void {
        Route::get('/@{username}', [PublicProfileController::class, 'show'])
            ->where('username', '[A-Za-z0-9._-]+')
            ->middleware(NoIndex::class)
            ->name('profiles.show');
        Route::get('/@{username}/feed.xml', [PublicFeedController::class, 'show'])
            ->where('username', '[A-Za-z0-9._-]+')
            ->middleware(NoIndex::class)
            ->name('profiles.feed');
        Route::get('/@{username}/images/{kind}', [PublicProfileImageController::class, 'show'])
            ->where([
                'username' => '[A-Za-z0-9._-]+',
                'kind' => 'avatar|cover',
            ])
            ->name('profiles.images.show');
        Route::get('/@{username}/{publicationSlug}/comments/{comment}/replies', PublicCommentRepliesController::class)
            ->where([
                'username' => '[A-Za-z0-9._-]+',
                'publicationSlug' => '[A-Za-z0-9-]+',
                'comment' => '[0-9]+',
            ])
            ->middleware(NoIndex::class)
            ->name('publications.comments.replies.index');
        Route::get('/@{username}/{publicationSlug}', [PublicPublicationController::class, 'show'])
            ->where([
                'username' => '[A-Za-z0-9._-]+',
                'publicationSlug' => '[A-Za-z0-9-]+',
            ])
            ->name('publications.show');
        Route::get('/publication-media/{publicationMedia}/{variant?}', [PublicPublicationMediaController::class, 'show'])
            ->where([
                'publicationMedia' => '[0-9]+',
                'variant' => 'original|thumbnail|medium|large',
            ])
            ->name('publications.media.show');
    });

    Route::middleware([NoIndex::class, PrivateResponse::class, 'throttle:share-read'])->group(function (): void {
        Route::get('/shares/{token}', [SharedContentController::class, 'show'])
            ->where('token', '[A-Za-z0-9_-]{43,}')
            ->name('shares.show');
        Route::get('/shares/{token}/attachments/{attachment}', [SharedAttachmentController::class, 'show'])
            ->where([
                'token' => '[A-Za-z0-9_-]{43,}',
                'attachment' => '[0-9]+',
            ])
            ->name('shares.attachments.show');
    });

    Route::post('/shares/{token}', [SharedContentController::class, 'show'])
        ->where('token', '[A-Za-z0-9_-]{43,}')
        ->middleware([NoIndex::class, PrivateResponse::class, 'throttle:share-password'])
        ->name('shares.unlock');

    Route::middleware(['auth', 'verified', EnsureAccountIsActive::class])->group(function (): void {
        Route::post('/publications/{publication}/comments', [PublicCommentController::class, 'store'])
            ->whereNumber('publication')
            ->middleware('throttle:public-comments')
            ->name('publications.comments.store');
        Route::post('/publications/{publication}/reactions', [PublicReactionController::class, 'store'])
            ->whereNumber('publication')
            ->middleware('throttle:public-reactions')
            ->name('publications.reactions.store');
        Route::post('/publications/{publication}/reports', [PublicReportController::class, 'publication'])
            ->whereNumber('publication')
            ->middleware('throttle:public-reports')
            ->name('publications.reports.store');
        Route::post('/comments/{comment}/reports', [PublicReportController::class, 'comment'])
            ->whereNumber('comment')
            ->middleware('throttle:public-reports')
            ->name('comments.reports.store');
        Route::delete('/comments/{comment}', PublicCommentDeletionController::class)
            ->whereNumber('comment')
            ->middleware('throttle:public-comment-deletions')
            ->name('comments.destroy');
    });

    Route::middleware(['auth', 'verified', EnsureAccountIsActive::class, PrivateResponse::class])
        ->prefix('app')
        ->scopeBindings()
        ->group(function (): void {
            Route::post('/memories', [EntryController::class, 'store'])
                ->middleware('throttle:entry-mutations')
                ->name('entries.store');
            Route::put('/memories/{entry}', [EntryController::class, 'update'])
                ->whereNumber('entry')
                ->middleware('throttle:entry-mutations')
                ->name('entries.update');
            Route::post('/memories/{entry}/versions/{version}/restore', [EntryVersionRestoreController::class, 'store'])
                ->whereNumber(['entry', 'version'])
                ->middleware('throttle:entry-mutations')
                ->name('entries.versions.restore');

            Route::post('/memories/{entry}/attachments', [AttachmentController::class, 'store'])
                ->whereNumber('entry')
                ->middleware('throttle:attachment-uploads')
                ->name('attachments.store');
            Route::get('/attachments/{attachment}/download', [AttachmentController::class, 'show'])
                ->whereNumber('attachment')
                ->middleware('throttle:private-downloads')
                ->name('attachments.download');
            Route::get('/publication-media/{publicationMedia}/preview/{variant?}', PublicationMediaPreviewController::class)
                ->where([
                    'publicationMedia' => '[0-9]+',
                    'variant' => 'original|thumbnail|medium|large',
                ])
                ->middleware('throttle:private-downloads')
                ->name('publications.media.preview');

            Route::middleware('throttle:publication-actions')->group(function (): void {
                Route::post('/memories/{entry}/publications', [EntryPublicationController::class, 'store'])
                    ->whereNumber('entry')
                    ->name('entry-publications.store');
                Route::post('/publications/{publication}/privacy-review', [PublicationPrivacyReviewController::class, 'store'])
                    ->whereNumber('publication')
                    ->name('app.publications.privacy-review.store');
                Route::post('/publications/{publication}/preview', [PublicationPreviewController::class, 'store'])
                    ->whereNumber('publication')
                    ->name('app.publications.preview.store');
                Route::post('/publications/{publication}/published', [PublishedPublicationController::class, 'store'])
                    ->whereNumber('publication')
                    ->name('app.publications.publish');
                Route::delete('/publications/{publication}/published', [PublishedPublicationController::class, 'destroy'])
                    ->whereNumber('publication')
                    ->name('app.publications.unpublish');
                Route::post('/publications/{publication}/schedule', [ScheduledPublicationController::class, 'store'])
                    ->whereNumber('publication')
                    ->name('app.publications.schedule');
                Route::delete('/publications/{publication}/schedule', [ScheduledPublicationController::class, 'destroy'])
                    ->whereNumber('publication')
                    ->name('app.publications.schedule.destroy');
            });

            Route::middleware('throttle:publication-previews')->group(function (): void {
                Route::get('/publications/{publication}/privacy-review', [PublicationPrivacyReviewController::class, 'show'])
                    ->whereNumber('publication')
                    ->name('app.publications.privacy-review');
                Route::get('/publications/{publication}/preview', [PublicationPreviewController::class, 'show'])
                    ->whereNumber('publication')
                    ->name('app.publications.preview');
            });

            Route::post('/memories/{entry}/share-links', [ShareLinkController::class, 'store'])
                ->whereNumber('entry')
                ->middleware('throttle:share-management')
                ->name('share-links.store');
            Route::delete('/share-links/{shareLink}', [ShareLinkController::class, 'destroy'])
                ->whereNumber('shareLink')
                ->middleware('throttle:share-management')
                ->name('share-links.destroy');

            Route::middleware('throttle:entry-sharing')->group(function (): void {
                Route::post('/memories/{entry}/entry-shares', [EntryShareController::class, 'store'])
                    ->whereNumber('entry')
                    ->name('entry-shares.store');
                Route::delete('/entry-shares/{entryShare}', [EntryShareController::class, 'destroy'])
                    ->whereNumber('entryShare')
                    ->name('entry-shares.destroy');
            });
            Route::get('/shared-memories', [SharedEntryController::class, 'index'])
                ->name('entries.shared.index');
            Route::get('/shared-memories/{entry}', [SharedEntryController::class, 'show'])
                ->whereNumber('entry')
                ->name('entries.shared.show');

            Route::post('/exports', [ExportController::class, 'store'])
                ->middleware('throttle:exports')
                ->name('exports.store');
            Route::get('/exports/{export}/download', [ExportController::class, 'download'])
                ->whereNumber('export')
                ->middleware('throttle:export-downloads')
                ->name('exports.download');
            Route::delete('/exports/{export}', [ExportController::class, 'destroy'])
                ->whereNumber('export')
                ->middleware('throttle:export-actions')
                ->name('exports.destroy');

            Route::get('/connected-accounts/{provider}/redirect', [ConnectedSocialAccountController::class, 'redirect'])
                ->middleware('throttle:social-oauth-starts')
                ->name('social.redirect');
            Route::get('/connected-accounts/{provider}/callback', [ConnectedSocialAccountController::class, 'callback'])
                ->middleware('throttle:social-oauth-callbacks')
                ->name('social.callback');
            Route::delete('/connected-accounts/{socialAccount}', [ConnectedSocialAccountController::class, 'destroy'])
                ->whereNumber('socialAccount')
                ->middleware('throttle:social-account-actions')
                ->name('social.disconnect');

            Route::delete('/account', [AccountDeletionController::class, 'destroy'])
                ->middleware('throttle:account-deletion')
                ->name('account.destroy');
        });
});
