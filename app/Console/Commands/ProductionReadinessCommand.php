<?php

namespace App\Console\Commands;

use App\Enums\SocialProvider;
use App\Services\ReviewedPublicUrl;
use App\Services\SocialOnboardingReadiness;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Log\LogManager;
use Illuminate\Mail\MailManager;
use Monolog\Level;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

#[Signature('memoria:release-check')]
#[Description('Validate production configuration before a release receives traffic')]
class ProductionReadinessCommand extends Command
{
    public function __construct(private readonly ReviewedPublicUrl $reviewedPublicUrl)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        /** @var list<string> $failures */
        $failures = [];

        $this->require($failures, $this->configurationString('app.env') === 'production', 'APP_ENV must be production.');
        $this->require($failures, config('app.debug') === false, 'APP_DEBUG must be false.');
        $this->require($failures, $this->reviewedPublicUrl->isValid(config('app.url')), 'APP_URL must be a canonical public HTTPS URL.');
        $this->require($failures, $this->hasSecureApplicationKey(config('app.key')), 'APP_KEY must be a non-placeholder 32-byte key.');

        $databaseConnection = $this->configurationString('database.default');
        $this->require($failures, $databaseConnection === 'pgsql', 'DB_CONNECTION must be pgsql.');
        $databaseSslMode = $this->configurationString("database.connections.{$databaseConnection}.sslmode");
        $this->require(
            $failures,
            in_array($databaseSslMode, ['require', 'verify-ca', 'verify-full'], true),
            'PostgreSQL must use DB_SSLMODE=require, verify-ca, or verify-full.',
        );

        $this->require($failures, $this->configurationString('cache.default') === 'redis', 'CACHE_STORE must be redis.');
        $this->require($failures, $this->configurationString('queue.default') === 'redis', 'QUEUE_CONNECTION must be redis.');
        $this->require($failures, $this->configurationString('session.driver') === 'redis', 'SESSION_DRIVER must be redis.');
        $this->require($failures, config('session.encrypt') === true, 'SESSION_ENCRYPT must be true.');
        $this->require($failures, config('session.secure') === true, 'SESSION_SECURE_COOKIE must be true.');
        $this->require($failures, config('session.http_only') === true, 'SESSION_HTTP_ONLY must be true.');
        $this->require(
            $failures,
            in_array($this->configurationString('session.same_site'), ['lax', 'strict'], true),
            'SESSION_SAME_SITE must be lax or strict.',
        );
        $this->require($failures, $this->configurationString('app.maintenance.driver') === 'cache', 'APP_MAINTENANCE_DRIVER must be cache.');
        $this->require($failures, $this->configurationString('app.maintenance.store') === 'redis', 'APP_MAINTENANCE_STORE must be redis.');
        $this->require($failures, $this->configurationString('queue.failed.driver') === 'database-uuids', 'QUEUE_FAILED_DRIVER must retain failed jobs in the database.');
        $this->require(
            $failures,
            (int) config('queue.connections.redis.retry_after') > 900,
            'REDIS_QUEUE_RETRY_AFTER must be greater than the 900-second export timeout.',
        );

        $this->require($failures, $this->trustedProxiesAreExplicit(), 'TRUSTED_PROXIES must contain only explicit IP addresses or CIDRs and must not be empty.');
        $this->require($failures, $this->mailerCanDeliver($this->configurationString('mail.default')), 'MAIL_MAILER must resolve only to delivery-capable transports.');
        $this->require($failures, $this->isProductionEmail(config('mail.from.address')), 'MAIL_FROM_ADDRESS must be a valid non-placeholder address.');
        $this->require($failures, $this->loggingIsProductionSafe(), 'The selected log channel and every stack member must use a non-debug level.');

        $scannerDriver = $this->configurationString('memoria.attachments.scanner.driver');
        $scannerBinary = $this->configurationString('memoria.attachments.scanner.binary');
        $this->require($failures, $scannerDriver === 'clamav', 'MEMORIA_ATTACHMENT_SCANNER must be clamav.');
        $this->require(
            $failures,
            $this->scannerIsClamAv($scannerBinary),
            'MEMORIA_CLAMAV_BINARY must resolve to ClamAV with a working signature database.',
        );

        $this->validateStorageBoundaries($failures);
        $this->validateRuntimeLimits($failures);
        $this->validateLegalDocuments($failures);
        $this->validateSocialDelivery($failures);

        if ($failures !== []) {
            $this->components->error(sprintf(
                'Production readiness check failed with %d blocking issue%s.',
                count($failures),
                count($failures) === 1 ? '' : 's',
            ));

            foreach ($failures as $failure) {
                $this->line("  - {$failure}");
            }

            return self::FAILURE;
        }

        $this->components->info('Production readiness check passed.');

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $failures
     */
    private function require(array &$failures, bool $condition, string $message): void
    {
        if (! $condition) {
            $failures[] = $message;
        }
    }

    private function configurationString(string $key): string
    {
        $value = config($key);

        return is_string($value) ? trim($value) : '';
    }

    private function hasSecureApplicationKey(mixed $key): bool
    {
        if (! is_string($key) || $key === '') {
            return false;
        }

        $decodedKey = str_starts_with($key, 'base64:')
            ? base64_decode(substr($key, 7), true)
            : $key;

        return is_string($decodedKey)
            && strlen($decodedKey) === 32
            && count(count_chars($decodedKey, 1)) >= 12;
    }

    private function trustedProxiesAreExplicit(): bool
    {
        $proxies = config('trustedproxy.proxies');

        if (! is_array($proxies) || $proxies === []) {
            return false;
        }

        foreach ($proxies as $proxy) {
            if (! is_string($proxy) || ! $this->isIpAddressOrCidr($proxy)) {
                return false;
            }
        }

        return true;
    }

    private function isIpAddressOrCidr(string $value): bool
    {
        $parts = explode('/', trim($value), 2);
        $address = $parts[0];
        $validatedAddress = filter_var($address, FILTER_VALIDATE_IP);

        if ($validatedAddress === false || in_array($address, ['0.0.0.0', '::'], true)) {
            return false;
        }

        if (! isset($parts[1])) {
            return true;
        }

        if ($parts[1] === '' || filter_var($parts[1], FILTER_VALIDATE_INT) === false) {
            return false;
        }

        $maximumPrefix = str_contains($address, ':') ? 128 : 32;
        $prefix = (int) $parts[1];

        return $prefix > 0 && $prefix <= $maximumPrefix;
    }

    /**
     * @param  list<string>  $visited
     */
    private function mailerCanDeliver(string $mailer, array $visited = []): bool
    {
        if ($mailer === '' || in_array($mailer, $visited, true)) {
            return false;
        }

        $configuration = config("mail.mailers.{$mailer}");

        if (! is_array($configuration)) {
            return false;
        }

        $transport = $configuration['transport'] ?? null;

        if (! is_string($transport) || in_array($transport, ['array', 'log', 'null'], true)) {
            return false;
        }

        if (! in_array($transport, ['failover', 'roundrobin'], true)) {
            return $this->mailTransportCanResolve($mailer, $transport);
        }

        $mailers = $configuration['mailers'] ?? null;

        if (! is_array($mailers) || $mailers === []) {
            return false;
        }

        $visited[] = $mailer;

        foreach ($mailers as $nestedMailer) {
            if (! is_string($nestedMailer) || ! $this->mailerCanDeliver($nestedMailer, $visited)) {
                return false;
            }
        }

        return $this->mailTransportCanResolve($mailer, $transport);
    }

    private function mailTransportCanResolve(string $mailer, string $transport): bool
    {
        if ($transport === 'sendmail') {
            $command = $this->configurationString("mail.mailers.{$mailer}.path");
            $binary = $this->commandBinary($command);

            if ($binary === '' || ! $this->executableExists($binary)) {
                return false;
            }
        }

        try {
            app(MailManager::class)->mailer($mailer)->getSymfonyTransport();
        } catch (Throwable) {
            return false;
        }

        return true;
    }

    private function commandBinary(string $command): string
    {
        if (preg_match('/^\s*(["\'])(.*?)\1/', $command, $matches) === 1) {
            return $matches[2];
        }

        $binary = strtok(trim($command), " \t");

        return is_string($binary) ? $binary : '';
    }

    private function isProductionEmail(mixed $address): bool
    {
        if (! is_string($address) || filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
            return false;
        }

        $domain = strtolower((string) strrchr($address, '@'));

        return ! in_array($domain, ['@example.com', '@example.org', '@example.net'], true)
            && ! str_ends_with($domain, '.example.com')
            && ! str_ends_with($domain, '.example.org')
            && ! str_ends_with($domain, '.example.net')
            && ! str_ends_with($domain, '.example');
    }

    private function loggingIsProductionSafe(): bool
    {
        return $this->logChannelIsProductionSafe($this->configurationString('logging.default'));
    }

    /**
     * @param  list<string>  $visited
     */
    private function logChannelIsProductionSafe(string $channel, array $visited = []): bool
    {
        if ($channel === '' || in_array($channel, $visited, true)) {
            return false;
        }

        $configuration = config("logging.channels.{$channel}");

        if (! is_array($configuration)) {
            return false;
        }

        if (($configuration['driver'] ?? null) !== 'stack') {
            $level = $configuration['level'] ?? null;

            if (! is_string($level)) {
                return false;
            }

            try {
                $resolvedLevel = Level::fromName($level);
                app(LogManager::class)->channel($channel);
            } catch (Throwable) {
                return false;
            }

            return $resolvedLevel !== Level::Debug;
        }

        $channels = $configuration['channels'] ?? null;

        if (! is_array($channels) || $channels === []) {
            return false;
        }

        $visited[] = $channel;

        foreach ($channels as $stackedChannel) {
            if (! is_string($stackedChannel) || ! $this->logChannelIsProductionSafe($stackedChannel, $visited)) {
                return false;
            }
        }

        try {
            app(LogManager::class)->channel($channel);
        } catch (Throwable) {
            return false;
        }

        return true;
    }

    private function executableExists(string $binary): bool
    {
        if ($binary === '') {
            return false;
        }

        if (str_contains($binary, '/') || str_contains($binary, '\\')) {
            return is_file($binary) && is_executable($binary);
        }

        return (new ExecutableFinder)->find($binary) !== null;
    }

    private function scannerIsClamAv(string $binary): bool
    {
        if (! $this->executableExists($binary)) {
            return false;
        }

        try {
            $versionProcess = new Process([$binary, '--version']);
            $versionProcess->setTimeout(5);
            $versionProcess->run();

            if (! $versionProcess->isSuccessful()
                || preg_match('/\bClamAV\b/i', $versionProcess->getOutput().$versionProcess->getErrorOutput()) !== 1) {
                return false;
            }

            $scanProcess = new Process([$binary, '--no-summary', '--stdout', '-']);
            $scanProcess->setTimeout(max(
                5,
                min(30, (int) config('memoria.attachments.scanner.timeout_seconds', 60)),
            ));
            $scanProcess->setInput($this->eicarReadinessPayload());
            $exitCode = $scanProcess->run();
        } catch (Throwable) {
            return false;
        }

        return $exitCode === 1
            && preg_match('/\bFOUND\b/i', $scanProcess->getOutput().$scanProcess->getErrorOutput()) === 1;
    }

    private function eicarReadinessPayload(): string
    {
        return implode('', [
            'X5O!P%@AP[4',
            '\\PZX54(P^)7CC)7}$',
            'EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$',
            'H+H*',
        ]);
    }

    /**
     * @param  list<string>  $failures
     */
    private function validateStorageBoundaries(array &$failures): void
    {
        $selectedDisks = [
            'MEMORIA_PRIVATE_DISK' => $this->configurationString('memoria.disks.private'),
            'MEMORIA_SANITIZED_MEDIA_DISK' => $this->configurationString('memoria.disks.sanitized_media'),
            'MEMORIA_EXPORT_DISK' => $this->configurationString('memoria.disks.exports'),
        ];
        $publicDisk = $this->configurationString('memoria.disks.public');
        $publicDiskConfiguration = config("filesystems.disks.{$publicDisk}");

        $this->require(
            $failures,
            count(array_unique(array_values($selectedDisks))) === count($selectedDisks)
                && ! in_array('', $selectedDisks, true),
            'Private, sanitized-media, and export storage must select three distinct disks.',
        );
        $this->require(
            $failures,
            $publicDisk !== ''
                && ! in_array($publicDisk, $selectedDisks, true)
                && is_array($publicDiskConfiguration),
            'MEMORIA_PUBLIC_DISK must select a configured disk distinct from every protected storage disk.',
        );

        /** @var list<array{label: string, bucket: string, root: string}> $boundaries */
        $boundaries = [];

        foreach ($selectedDisks as $environmentName => $disk) {
            if ($disk === '') {
                continue;
            }

            $configuration = config("filesystems.disks.{$disk}");
            $validConfiguration = is_array($configuration);
            $bucket = $validConfiguration && is_string($configuration['bucket'] ?? null)
                ? trim($configuration['bucket'])
                : '';
            $root = $validConfiguration && is_string($configuration['root'] ?? null)
                ? $this->normaliseStorageRoot($configuration['root'])
                : '';

            $this->require(
                $failures,
                $validConfiguration
                    && ($configuration['driver'] ?? null) === 's3'
                    && ($configuration['visibility'] ?? null) === 'private'
                    && ($configuration['directory_visibility'] ?? null) === 'private'
                    && ($configuration['throw'] ?? null) === true
                    && ($configuration['report'] ?? null) === true
                    && filled($configuration['region'] ?? null)
                    && $bucket !== ''
                    && $root !== '',
                "{$environmentName} must select a private, fail-closed S3 disk with region, bucket, and root.",
            );

            if ($bucket !== '' && $root !== '') {
                $boundaries[] = [
                    'label' => $environmentName,
                    'bucket' => $bucket,
                    'root' => $root,
                ];
            }
        }

        foreach ($boundaries as $index => $boundary) {
            foreach (array_slice($boundaries, $index + 1) as $otherBoundary) {
                $this->require(
                    $failures,
                    $boundary['bucket'] !== $otherBoundary['bucket']
                        || ! $this->storageRootsOverlap($boundary['root'], $otherBoundary['root']),
                    "{$boundary['label']} and {$otherBoundary['label']} must not use overlapping roots in the same bucket.",
                );
            }
        }

        if (is_array($publicDiskConfiguration) && ($publicDiskConfiguration['driver'] ?? null) === 's3') {
            $publicBucket = is_string($publicDiskConfiguration['bucket'] ?? null)
                ? trim($publicDiskConfiguration['bucket'])
                : '';
            $publicRoot = is_string($publicDiskConfiguration['root'] ?? null)
                ? $this->normaliseStorageRoot($publicDiskConfiguration['root'])
                : '';

            $this->require(
                $failures,
                $publicBucket !== '',
                'MEMORIA_PUBLIC_DISK must define its physical S3 bucket boundary.',
            );

            foreach ($boundaries as $boundary) {
                $this->require(
                    $failures,
                    $publicBucket === ''
                        || $publicBucket !== $boundary['bucket']
                        || ! $this->storageRootsOverlap($publicRoot, $boundary['root']),
                    "MEMORIA_PUBLIC_DISK must not overlap {$boundary['label']} in the same bucket.",
                );
            }
        }

        $temporaryUploadDisk = $this->configurationString('livewire.temporary_file_upload.disk');
        $temporaryUploadConfiguration = config("filesystems.disks.{$temporaryUploadDisk}");
        $this->require(
            $failures,
            $temporaryUploadDisk === $selectedDisks['MEMORIA_PRIVATE_DISK']
                && is_array($temporaryUploadConfiguration)
                && ($temporaryUploadConfiguration['driver'] ?? null) === 's3'
                && ($temporaryUploadConfiguration['visibility'] ?? null) === 'private'
                && ($temporaryUploadConfiguration['throw'] ?? null) === true,
            'LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK must select MEMORIA_PRIVATE_DISK.',
        );
    }

    private function normaliseStorageRoot(string $root): string
    {
        return trim(str_replace('\\', '/', $root), " /\t\n\r\0\x0B");
    }

    private function storageRootsOverlap(string $first, string $second): bool
    {
        return $first === ''
            || $second === ''
            || $first === $second
            || str_starts_with("{$first}/", "{$second}/")
            || str_starts_with("{$second}/", "{$first}/");
    }

    /**
     * @param  list<string>  $failures
     */
    private function validateRuntimeLimits(array &$failures): void
    {
        $maximumUploadBytes = max(1, (int) config('memoria.attachments.maximum_kilobytes')) * 1024;
        $uploadLimit = $this->iniBytes(ini_get('upload_max_filesize'));
        $postLimit = $this->iniBytes(ini_get('post_max_size'), true);
        $memoryLimit = $this->iniBytes(ini_get('memory_limit'), true);

        $this->require($failures, $uploadLimit >= $maximumUploadBytes, 'PHP upload_max_filesize is below MEMORIA_ATTACHMENT_MAX_KILOBYTES.');
        $this->require($failures, $postLimit >= $maximumUploadBytes, 'PHP post_max_size is below MEMORIA_ATTACHMENT_MAX_KILOBYTES.');
        $this->require($failures, $memoryLimit >= 512 * 1024 * 1024, 'PHP memory_limit must be at least 512M.');
    }

    private function iniBytes(string|false $value, bool $zeroIsUnlimited = false): int
    {
        if ($value === false) {
            return 0;
        }

        $value = strtolower(trim($value));

        if ($value === '-1' || ($zeroIsUnlimited && $value === '0')) {
            return PHP_INT_MAX;
        }

        if (! preg_match('/^(\d+(?:\.\d+)?)\s*([kmgt]?)$/', $value, $matches)) {
            return 0;
        }

        $bytes = (float) $matches[1];
        $power = match ($matches[2]) {
            'k' => 1,
            'm' => 2,
            'g' => 3,
            't' => 4,
            default => 0,
        };

        return (int) ($bytes * (1024 ** $power));
    }

    /**
     * @param  list<string>  $failures
     */
    private function validateLegalDocuments(array &$failures): void
    {
        $privacyUrl = config('memoria.legal.privacy_notice_url');
        $termsUrl = config('memoria.legal.terms_of_service_url');
        $privacyCanonicalUrl = $this->reviewedPublicUrl->canonicalize($privacyUrl, true);
        $termsCanonicalUrl = $this->reviewedPublicUrl->canonicalize($termsUrl, true);
        $canonicalAppUrl = $this->reviewedPublicUrl->canonicalize(config('app.url'));
        $localLegalUrls = $canonicalAppUrl === null
            ? []
            : [
                rtrim($canonicalAppUrl, '/').route('privacy', absolute: false),
                rtrim($canonicalAppUrl, '/').route('terms', absolute: false),
            ];

        $this->require($failures, $privacyCanonicalUrl !== null, 'MEMORIA_PRIVACY_NOTICE_URL must point to a reviewed public HTTPS document.');
        $this->require($failures, $termsCanonicalUrl !== null, 'MEMORIA_TERMS_OF_SERVICE_URL must point to a reviewed public HTTPS document.');
        $this->require(
            $failures,
            $privacyCanonicalUrl === null || ! $this->matchesAnyUrl($privacyUrl, $localLegalUrls),
            'MEMORIA_PRIVACY_NOTICE_URL must not redirect to a local legal route.',
        );
        $this->require(
            $failures,
            $termsCanonicalUrl === null || ! $this->matchesAnyUrl($termsUrl, $localLegalUrls),
            'MEMORIA_TERMS_OF_SERVICE_URL must not redirect to a local legal route.',
        );
        $this->require(
            $failures,
            $privacyCanonicalUrl !== null
                && $termsCanonicalUrl !== null
                && $privacyCanonicalUrl !== $termsCanonicalUrl,
            'Privacy and terms must use distinct reviewed document URLs.',
        );
    }

    /**
     * @param  list<string>  $candidates
     */
    private function matchesAnyUrl(mixed $url, array $candidates): bool
    {
        foreach ($candidates as $candidate) {
            if ($this->reviewedPublicUrl->areEquivalent($url, $candidate, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $failures
     */
    private function validateSocialDelivery(array &$failures): void
    {
        $driver = $this->configurationString('memoria.social.driver');

        $this->require(
            $failures,
            in_array($driver, ['disabled', 'real'], true),
            'MEMORIA_SOCIAL_DRIVER must be disabled or real in production.',
        );

        if ($driver !== 'real') {
            return;
        }

        $readiness = app(SocialOnboardingReadiness::class);
        $hasAvailableProvider = $readiness->for(SocialProvider::X)['available']
            || $readiness->for(SocialProvider::LinkedIn)['available'];

        $this->require(
            $failures,
            $hasAvailableProvider,
            'Real social delivery requires complete OAuth configuration for X or LinkedIn.',
        );
    }
}
