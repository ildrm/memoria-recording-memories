<?php

namespace App\Http\Controllers;

use App\Actions\RestoreEntryVersion;
use App\Models\Entry;
use App\Models\EntryVersion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class EntryVersionRestoreController extends Controller
{
    public function store(
        Request $request,
        Entry $entry,
        EntryVersion $version,
        RestoreEntryVersion $restoreEntryVersion,
    ): RedirectResponse {
        $restoreEntryVersion->handle($entry, $version, $request->user());

        return back()->with('status', __('The selected memory version was restored.'));
    }
}
