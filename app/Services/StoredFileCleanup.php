<?php

namespace App\Services;

use App\Jobs\DeleteStoredFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class StoredFileCleanup
{
    public function schedule(
        string $disk,
        string $path,
        string $reason,
        bool $dispatchImmediately = true,
    ): int {
        $disk = trim($disk);
        $path = trim($path);
        if ($disk === '' || $path === '' || str_contains($path, '..')) {
            throw new InvalidArgumentException('The stored file cleanup target is invalid.');
        }

        $pathHash = hash_hmac('sha256', $disk."\0".$path, (string) config('app.key'));
        $now = now();

        DB::table('stored_file_deletions')->upsert([[
            'disk' => $disk,
            'path_hash' => $pathHash,
            'encrypted_path' => Crypt::encryptString($path),
            'reason' => Str::limit($reason, 120, ''),
            'attempts' => 0,
            'last_attempted_at' => null,
            'last_error_code' => null,
            'completed_at' => null,
            'failed_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]], ['disk', 'path_hash'], [
            'encrypted_path',
            'reason',
            'attempts',
            'last_attempted_at',
            'last_error_code',
            'completed_at',
            'failed_at',
            'updated_at',
        ]);

        $deletionId = (int) DB::table('stored_file_deletions')
            ->where('disk', $disk)
            ->where('path_hash', $pathHash)
            ->value('id');

        if ($dispatchImmediately) {
            DeleteStoredFile::dispatch($deletionId)->afterCommit();
        }

        return $deletionId;
    }
}
