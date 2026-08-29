<?php

namespace App\Http\Controllers;

use App\Actions\RequestUserExport;
use App\Http\Requests\StoreExportRequest;
use App\Models\Export;
use App\Services\StoredFileCleanup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

use function Illuminate\Support\enum_value;

class ExportController extends Controller
{
    public function store(
        StoreExportRequest $request,
        RequestUserExport $requestUserExport,
    ): JsonResponse {
        $validated = $request->validated();
        $export = $requestUserExport->handle(
            owner: $request->user(),
            formats: $validated['formats'] ?? ['json', 'markdown'],
            includeAttachments: (bool) ($validated['include_attachments'] ?? true),
        );

        return response()->json(['data' => [
            'id' => $export->getKey(),
            'status' => enum_value($export->status),
        ]], 202);
    }

    public function download(Export $export): StreamedResponse
    {
        Gate::authorize('download', $export);
        abort_if($export->disk === null || $export->path === null, 404);
        abort_unless(Storage::disk($export->disk)->exists($export->path), 404);

        return Storage::disk($export->disk)->download(
            $export->path,
            str_replace(["\r", "\n", '"', '/', '\\'], '-', basename((string) $export->filename)),
            [
                'Content-Type' => 'application/zip',
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'private, no-store, max-age=0',
            ],
        );
    }

    public function destroy(
        Export $export,
        StoredFileCleanup $storedFileCleanup,
    ): RedirectResponse {
        Gate::authorize('delete', $export);

        DB::transaction(function () use ($export, $storedFileCleanup): void {
            $export = Export::query()->lockForUpdate()->findOrFail($export->getKey());
            Gate::authorize('delete', $export);

            if ($export->disk !== null && $export->path !== null) {
                $storedFileCleanup->schedule(
                    $export->disk,
                    $export->path,
                    'user_export_deleted',
                );
            }

            $export->delete();
        });

        return back()->with('status', __('Export removed.'));
    }
}
