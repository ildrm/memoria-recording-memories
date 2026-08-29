<?php

namespace App\Http\Controllers;

use App\Enums\AttachmentScanStatus;
use App\Http\Requests\ResolveShareLinkRequest;
use App\Services\ShareLinkAccessSession;
use App\Services\ShareLinkResolver;
use App\Services\ShareLinks\InvalidShareLink;
use App\Services\ShareLinks\InvalidSharePassword;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;

class SharedContentController extends Controller
{
    public function show(
        ResolveShareLinkRequest $request,
        string $token,
        ShareLinkResolver $resolver,
        ShareLinkAccessSession $accessSession,
    ): View {
        $passwordVerified = $accessSession->isGranted($request, $token);
        $password = $request->isMethod('post')
            ? $request->validated('password')
            : null;

        try {
            $shareLink = $resolver->resolve(
                token: $token,
                password: $password,
                passwordVerified: $passwordVerified,
            );
        } catch (InvalidShareLink) {
            abort(404);
        } catch (InvalidSharePassword $exception) {
            if ($request->isMethod('post')) {
                throw ValidationException::withMessages([
                    'password' => [__('The password is incorrect or the link is unavailable.')],
                ]);
            }

            return view('public.share', [
                'share' => $exception->shareLink,
                'state' => 'locked',
                'token' => $token,
            ]);
        }

        $accessSession->grant($request, $token);

        if ($shareLink->entry !== null) {
            $entry = $shareLink->entry;
            if ($shareLink->include_attachments) {
                $entry->loadMissing([
                    'attachments' => fn ($query) => $query
                        ->where('scan_status', AttachmentScanStatus::Clean)
                        ->orderBy('id'),
                ]);
            }

            return view('public.share', [
                'share' => $shareLink,
                'content' => $entry,
                'state' => 'available',
                'token' => $token,
            ]);
        }

        $publication = $shareLink->publication?->loadMissing(['owner.profile', 'media']);
        abort_if($publication === null, 404);

        return view('public.share', [
            'share' => $shareLink,
            'content' => $publication,
            'state' => 'available',
        ]);
    }
}
