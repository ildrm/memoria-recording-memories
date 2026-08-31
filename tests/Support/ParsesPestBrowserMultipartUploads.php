<?php

namespace Tests\Support;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\Response;

final class ParsesPestBrowserMultipartUploads
{
    public function handle(Request $request, Closure $next): Response
    {
        $temporaryPaths = [];

        if ($this->shouldParse($request)) {
            $temporaryPaths = $this->populateUploadedFiles($request);
        }

        try {
            return $next($request);
        } finally {
            foreach ($temporaryPaths as $temporaryPath) {
                @unlink($temporaryPath);
            }
        }
    }

    private function shouldParse(Request $request): bool
    {
        return $request->isMethod('POST')
            && str_ends_with($request->path(), '/upload-file')
            && $request->files->count() === 0
            && str_starts_with(mb_strtolower((string) $request->header('content-type')), 'multipart/form-data');
    }

    /**
     * Pest Browser's in-process HTTP server currently forwards the multipart body but leaves
     * Symfony's file bag empty. Rehydrate that bag so the real Livewire upload endpoint runs.
     *
     * @return list<string>
     */
    private function populateUploadedFiles(Request $request): array
    {
        $contentType = (string) $request->header('content-type');
        preg_match('/boundary=(?:"([^"]+)"|([^;]+))/i', $contentType, $boundaryMatch);
        $boundary = trim((string) (($boundaryMatch[1] ?? '') ?: ($boundaryMatch[2] ?? '')));

        if ($boundary === '') {
            return [];
        }

        $uploadedFiles = [];
        $temporaryPaths = [];

        foreach (explode('--'.$boundary, $request->getContent()) as $part) {
            $part = ltrim($part, "\r\n");

            if ($part === '' || str_starts_with($part, '--')) {
                continue;
            }

            $sections = explode("\r\n\r\n", $part, 2);

            if (count($sections) !== 2) {
                continue;
            }

            [$headers, $contents] = $sections;
            preg_match('/content-disposition:\s*form-data;\s*name="([^"]+)"(?:;\s*filename="([^"]*)")?/i', $headers, $dispositionMatch);
            $fieldName = mb_rtrim((string) ($dispositionMatch[1] ?? ''), '[]');
            $fileName = (string) ($dispositionMatch[2] ?? '');

            if ($fieldName === '' || $fileName === '') {
                continue;
            }

            preg_match('/content-type:\s*([^\r\n]+)/i', $headers, $mimeTypeMatch);
            $mimeType = trim((string) ($mimeTypeMatch[1] ?? 'application/octet-stream'));
            $contents = str_ends_with($contents, "\r\n") ? substr($contents, 0, -2) : $contents;
            $temporaryPath = tempnam(sys_get_temp_dir(), 'memoria-browser-upload-');

            if ($temporaryPath === false) {
                continue;
            }

            file_put_contents($temporaryPath, $contents);
            $temporaryPaths[] = $temporaryPath;
            $uploadedFiles[$fieldName][] = new UploadedFile(
                $temporaryPath,
                basename($fileName),
                $mimeType,
                UPLOAD_ERR_OK,
                true,
            );
        }

        foreach ($uploadedFiles as $fieldName => $files) {
            $request->files->set($fieldName, $files);
            $request->request->set($fieldName, $files);
        }

        return $temporaryPaths;
    }
}
