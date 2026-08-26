<?php

namespace App\Http\Controllers\Admin;

use App\Models\Agent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class AgentLauncherIconController
{
    public const DISK = 'public';

    public const DIR = 'agent-launcher-icons';

    public function store(Request $request, Agent $agent): RedirectResponse
    {
        $request->user()->can('update', $agent) || abort(403);

        $request->validate([
            'icon' => [
                'required',
                'file',
                'max:256',
                'mimes:png,jpg,jpeg,webp,svg',
            ],
        ]);

        $file = $request->file('icon');
        $extension = strtolower((string) $file->getClientOriginalExtension());
        $filename = $agent->id.'.'.$extension;

        $this->deleteExisting($agent);

        Storage::disk(self::DISK)->putFileAs(
            self::DIR,
            $file,
            $filename,
        );

        $url = Storage::disk(self::DISK)->url(self::DIR.'/'.$filename);

        $theme = (array) ($agent->theme ?? []);
        $theme['launcher_icon_url'] = $url;
        $agent->update(['theme' => $theme]);

        return back()->with('success', 'Launcher icon updated.');
    }

    public function destroy(Request $request, Agent $agent): RedirectResponse
    {
        $request->user()->can('update', $agent) || abort(403);

        $this->deleteExisting($agent);

        $theme = (array) ($agent->theme ?? []);
        unset($theme['launcher_icon_url']);
        $agent->update(['theme' => $theme]);

        return back()->with('success', 'Launcher icon removed.');
    }

    /**
     * Remove any previously stored launcher icon for this agent so a
     * stale .png isn't left behind when the admin uploads a .webp.
     */
    private function deleteExisting(Agent $agent): void
    {
        foreach (['png', 'jpg', 'jpeg', 'webp', 'svg'] as $ext) {
            $path = self::DIR.'/'.$agent->id.'.'.$ext;
            if (Storage::disk(self::DISK)->exists($path)) {
                Storage::disk(self::DISK)->delete($path);
            }
        }
    }
}
