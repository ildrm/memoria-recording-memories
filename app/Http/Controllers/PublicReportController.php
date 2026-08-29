<?php

namespace App\Http\Controllers;

use App\Actions\CreatePublicReport;
use App\Http\Requests\StorePublicReportRequest;
use App\Models\Comment;
use App\Models\Publication;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

use function Illuminate\Support\enum_value;

class PublicReportController extends Controller
{
    public function publication(
        StorePublicReportRequest $request,
        Publication $publication,
        CreatePublicReport $createPublicReport,
    ): JsonResponse|RedirectResponse {
        return $this->store($request, $publication, $createPublicReport);
    }

    public function comment(
        StorePublicReportRequest $request,
        Comment $comment,
        CreatePublicReport $createPublicReport,
    ): JsonResponse|RedirectResponse {
        return $this->store($request, $comment, $createPublicReport);
    }

    private function store(
        StorePublicReportRequest $request,
        Publication|Comment $target,
        CreatePublicReport $createPublicReport,
    ): JsonResponse|RedirectResponse {
        $report = $createPublicReport->handle(
            reporter: $request->user(),
            target: $target,
            reason: $request->validated('reason'),
            details: $request->validated('details'),
            request: $request,
        );

        if ($request->expectsJson()) {
            return response()->json(['data' => [
                'id' => $report->getKey(),
                'status' => enum_value($report->status),
            ]], 202);
        }

        return back()->with('status', __('Thank you. The report was submitted for review.'));
    }
}
