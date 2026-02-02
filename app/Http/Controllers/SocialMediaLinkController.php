<?php

namespace App\Http\Controllers;

use App\Models\SocialMediaLink;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SocialMediaLinkController extends Controller
{
    public function store(Request $request)
    {
        $request->validate([
            'links' => 'required|array',
            'links.*.platform' => ['required', 'string', Rule::in(SocialMediaLink::PLATFORMS)],
            'links.*.username' => 'required|string|max:100',
        ]);

        $user = $request->user();

        foreach ($request->links as $link) {
            $user->socialMediaLinks()->updateOrCreate(
                ['platform' => $link['platform']],
                ['username' => $link['username']]
            );
        }

        // touch() forces updated_at change and triggers UserObserver for Elasticsearch reindex
        $user->touch();

        return response()->json([
            'message' => 'Social media links updated successfully',
            'social_media_links' => $user->socialMediaLinks->map(fn($link) => [
                'platform' => $link->platform,
                'username' => $link->username,
                'url' => $link->url,
            ]),
        ]);
    }

    public function destroy(Request $request, string $platform)
    {
        $user = $request->user();

        $deleted = $user->socialMediaLinks()
            ->where('platform', $platform)
            ->delete();

        if (!$deleted) {
            return response()->json(['message' => 'Social media link not found'], 404);
        }

        // touch() forces updated_at change and triggers UserObserver for Elasticsearch reindex
        $user->touch();

        return response()->json(['message' => 'Social media link deleted successfully']);
    }
}
