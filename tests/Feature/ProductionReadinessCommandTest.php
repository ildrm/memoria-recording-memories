<?php

use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

afterEach(function (): void {
    $fixture = releaseClamAvFixturePath();

    if (is_file($fixture)) {
        unlink($fixture);
    }
});

test('the production readiness check reports blocking configuration', function (): void {
    $exitCode = Artisan::call('memoria:release-check');

    expect($exitCode)->toBe(Command::FAILURE)
        ->and(Artisan::output())
        ->toContain('APP_ENV must be production.')
        ->toContain('MEMORIA_PRIVACY_NOTICE_URL')
        ->toContain('MEMORIA_TERMS_OF_SERVICE_URL');
});

test('the production readiness check passes a complete deployment configuration', function (): void {
    $originalMemoryLimit = ini_get('memory_limit');
    ini_set('memory_limit', '512M');

    try {
        configureReleaseReadyApplication();

        $exitCode = Artisan::call('memoria:release-check');

        expect($exitCode)->toBe(Command::SUCCESS)
            ->and(Artisan::output())->toContain('Production readiness check passed.');
    } finally {
        if ($originalMemoryLimit !== false) {
            ini_set('memory_limit', $originalMemoryLimit);
        }
    }
});

test('the production readiness check rejects reserved production hostnames', function (): void {
    configureReleaseReadyApplication();
    config(['app.url' => 'https://deploy.example.com']);

    $exitCode = Artisan::call('memoria:release-check');

    expect($exitCode)->toBe(Command::FAILURE)
        ->and(Artisan::output())->toContain('APP_URL must be a canonical public HTTPS URL.');
});

test('the production readiness check rejects placeholder Livewire release tokens', function (string $releaseToken): void {
    configureReleaseReadyApplication();
    config(['livewire.release_token' => $releaseToken]);

    $exitCode = Artisan::call('memoria:release-check');

    expect($exitCode)->toBe(Command::FAILURE)
        ->and(Artisan::output())
        ->toContain('LIVEWIRE_RELEASE_TOKEN must identify this exact deployment');
})->with([
    'package default' => 'a',
    'generic placeholder' => 'development',
]);

test('the production readiness check rejects weak secrets and unresolved delivery configuration', function (): void {
    configureReleaseReadyApplication();
    config([
        'app.key' => 'base64:'.base64_encode(str_repeat('A', 31).'B'),
        'mail.default' => 'postmark',
        'logging.channels.single.level' => 'warnng',
        'memoria.attachments.scanner.binary' => PHP_BINARY,
    ]);

    $exitCode = Artisan::call('memoria:release-check');

    expect($exitCode)->toBe(Command::FAILURE)
        ->and(Artisan::output())
        ->toContain('APP_KEY must be a non-placeholder 32-byte key.')
        ->toContain('MAIL_MAILER must resolve only to delivery-capable transports.')
        ->toContain('The selected log channel and every stack member must use a non-debug level.')
        ->toContain('MEMORIA_CLAMAV_BINARY must resolve to ClamAV with a working signature database.');
});

test('the production readiness check rejects PHP limits below the configured attachment size', function (): void {
    configureReleaseReadyApplication();
    config(['memoria.attachments.maximum_kilobytes' => 1024 * 1024]);

    $exitCode = Artisan::call('memoria:release-check');

    expect($exitCode)->toBe(Command::FAILURE)
        ->and(Artisan::output())
        ->toContain('PHP upload_max_filesize is below MEMORIA_ATTACHMENT_MAX_KILOBYTES.')
        ->toContain('PHP post_max_size is below MEMORIA_ATTACHMENT_MAX_KILOBYTES.');
});

test('the production readiness check requires ClamAV signatures that detect malware', function (): void {
    configureReleaseReadyApplication();
    config(['memoria.attachments.scanner.binary' => createReleaseClamAvFixture(false)]);

    $exitCode = Artisan::call('memoria:release-check');

    expect($exitCode)->toBe(Command::FAILURE)
        ->and(Artisan::output())
        ->toContain('MEMORIA_CLAMAV_BINARY must resolve to ClamAV with a working signature database.');
});

test('the production readiness check compares physical storage boundaries', function (): void {
    configureReleaseReadyApplication();
    config([
        'memoria.disks.public' => 'public_alias',
        'filesystems.disks.public_alias' => [
            'driver' => 's3',
            'region' => 'us-east-1',
            'bucket' => 'memoria-production',
            'root' => 'private/public',
        ],
    ]);

    $exitCode = Artisan::call('memoria:release-check');

    expect($exitCode)->toBe(Command::FAILURE)
        ->and(Artisan::output())
        ->toContain('MEMORIA_PUBLIC_DISK must not overlap MEMORIA_PRIVATE_DISK in the same bucket.');
});

test('the production readiness check rejects equivalent and cyclic local legal URLs', function (): void {
    configureReleaseReadyApplication();
    config([
        'memoria.legal.privacy_notice_url' => 'https://MEMORIA.APP:443/terms/',
        'memoria.legal.terms_of_service_url' => 'https://memoria.app/%70rivacy',
    ]);

    $exitCode = Artisan::call('memoria:release-check');

    expect($exitCode)->toBe(Command::FAILURE)
        ->and(Artisan::output())
        ->toContain('MEMORIA_PRIVACY_NOTICE_URL must not redirect to a local legal route.')
        ->toContain('MEMORIA_TERMS_OF_SERVICE_URL must not redirect to a local legal route.');
});

test('legal routes redirect to operator reviewed documents', function (): void {
    config([
        'memoria.legal.privacy_notice_url' => 'https://legal.memoria.app/privacy',
        'memoria.legal.terms_of_service_url' => 'https://legal.memoria.app/terms',
    ]);

    $this->get(route('privacy'))
        ->assertRedirect('https://legal.memoria.app/privacy')
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');

    $this->get(route('terms'))
        ->assertRedirect('https://legal.memoria.app/terms')
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
});

test('legal templates remain available outside production', function (): void {
    config([
        'memoria.legal.privacy_notice_url' => null,
        'memoria.legal.terms_of_service_url' => null,
    ]);

    $this->get(route('privacy'))
        ->assertOk()
        ->assertSee('Privacy notice template')
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');

    $this->get(route('terms'))
        ->assertOk()
        ->assertSee('Terms of service template')
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
});

test('production never serves unreviewed legal templates', function (): void {
    $originalEnvironment = app()->environment();
    app()->detectEnvironment(static fn (): string => 'production');
    config([
        'memoria.legal.privacy_notice_url' => null,
        'memoria.legal.terms_of_service_url' => null,
    ]);

    try {
        $this->get(route('privacy'))->assertServiceUnavailable();
        $this->get(route('terms'))->assertServiceUnavailable();

        config(['memoria.legal.privacy_notice_url' => 'https://localhost/privacy']);
        $this->get(route('privacy'))->assertServiceUnavailable();

        config(['memoria.legal.privacy_notice_url' => 'https://privacy.example.com/privacy']);
        $this->get(route('privacy'))->assertServiceUnavailable();

        config([
            'app.url' => 'https://memoria.app',
            'memoria.legal.privacy_notice_url' => 'https://MEMORIA.APP:443/terms/',
            'memoria.legal.terms_of_service_url' => 'https://memoria.app/%70rivacy',
        ]);
        $this->get('https://memoria.app/privacy')->assertServiceUnavailable();
        $this->get('https://memoria.app/terms')->assertServiceUnavailable();
    } finally {
        Request::setTrustedHosts([]);
        app()->detectEnvironment(static fn (): string => $originalEnvironment);
    }
});

function configureReleaseReadyApplication(): void
{
    $diskConfiguration = static fn (string $root): array => [
        'driver' => 's3',
        'region' => 'us-east-1',
        'bucket' => 'memoria-production',
        'root' => $root,
        'visibility' => 'private',
        'directory_visibility' => 'private',
        'throw' => true,
        'report' => true,
    ];

    config([
        'app.env' => 'production',
        'app.debug' => false,
        'app.url' => 'https://memoria.app',
        'app.key' => 'base64:'.base64_encode(hash('sha256', 'memoria-release-test-key', true)),
        'app.maintenance.driver' => 'cache',
        'app.maintenance.store' => 'redis',
        'database.default' => 'pgsql',
        'database.connections.pgsql.sslmode' => 'require',
        'cache.default' => 'redis',
        'queue.default' => 'redis',
        'queue.failed.driver' => 'database-uuids',
        'queue.connections.redis.retry_after' => 960,
        'session.driver' => 'redis',
        'session.encrypt' => true,
        'session.secure' => true,
        'session.http_only' => true,
        'session.same_site' => 'lax',
        'trustedproxy.proxies' => ['10.0.0.0/8'],
        'mail.default' => 'smtp',
        'mail.from.address' => 'release@memoria.app',
        'logging.default' => 'single',
        'logging.channels.single.level' => 'warning',
        'memoria.attachments.scanner.driver' => 'clamav',
        'memoria.attachments.scanner.binary' => createReleaseClamAvFixture(),
        'memoria.attachments.maximum_kilobytes' => 1024,
        'memoria.disks.private' => 'release_private',
        'memoria.disks.sanitized_media' => 'release_sanitized',
        'memoria.disks.exports' => 'release_exports',
        'memoria.disks.public' => 'public',
        'memoria.legal.privacy_notice_url' => 'https://legal.memoria.app/privacy',
        'memoria.legal.terms_of_service_url' => 'https://legal.memoria.app/terms',
        'memoria.social.driver' => 'disabled',
        'filesystems.disks.release_private' => $diskConfiguration('private'),
        'filesystems.disks.release_sanitized' => $diskConfiguration('sanitized-media'),
        'filesystems.disks.release_exports' => $diskConfiguration('exports'),
        'livewire.release_token' => '341bcddce9eae98b321d2e986163b8d9f0ba6553',
        'livewire.temporary_file_upload.disk' => 'release_private',
    ]);
}

function createReleaseClamAvFixture(bool $detectsEicar = true): string
{
    $path = releaseClamAvFixturePath();
    $scanExitCode = $detectsEicar ? 1 : 0;
    $scanResult = $detectsEicar ? 'stdin: Win.Test.EICAR_HDB-1 FOUND' : 'stdin: OK';
    $contents = PHP_OS_FAMILY === 'Windows'
        ? "@echo off\r\nif \"%~1\"==\"--version\" (\r\n  echo ClamAV 1.4.0/test\r\n  exit /b 0\r\n)\r\necho {$scanResult}\r\nexit /b {$scanExitCode}\r\n"
        : "#!/bin/sh\nif [ \"\$1\" = \"--version\" ]; then\n  printf 'ClamAV 1.4.0/test\\n'\n  exit 0\nfi\nprintf '{$scanResult}\\n'\nexit {$scanExitCode}\n";

    if (file_put_contents($path, $contents) === false) {
        throw new RuntimeException('Unable to create the ClamAV test fixture.');
    }

    if (PHP_OS_FAMILY !== 'Windows') {
        chmod($path, 0700);
    }

    return $path;
}

function releaseClamAvFixturePath(): string
{
    return sys_get_temp_dir().DIRECTORY_SEPARATOR.'memoria-release-clamscan-'.getmypid()
        .(PHP_OS_FAMILY === 'Windows' ? '.bat' : '');
}
