<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\ClientNote;
use App\Models\User;
use App\Models\UserTag;
use App\Models\UserTagCategory;
use Illuminate\Support\Collection;

class ClientInsightsService
{
    public function getClients(User $artist, ?string $search = null): Collection
    {
        $clientIds = Appointment::where('artist_id', $artist->id)
            ->whereNotNull('client_id')
            ->distinct()
            ->pluck('client_id');

        $query = User::whereIn('id', $clientIds);

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('username', 'like', "%{$search}%");
            });
        }

        $clients = $query->orderBy('name')->get();

        return $clients->map(function ($client) use ($artist) {
            $appointments = Appointment::where('client_id', $client->id)
                ->where('artist_id', $artist->id)
                ->get();

            $completed = $appointments->where('status', 'completed');
            $nextAppt = $appointments->where('status', 'booked')
                ->where('date', '>=', now()->startOfDay())
                ->sortBy('date')
                ->first();

            $tagCount = UserTag::where('client_id', $client->id)
                ->whereIn('tag_category_id',
                    UserTagCategory::where('studio_user_id', $artist->id)->pluck('id')
                )->count();

            return [
                'id' => $client->id,
                'name' => $client->name,
                'email' => $client->email,
                'sessions' => $completed->count(),
                'next_appointment' => $nextAppt?->date->toDateString(),
                'tag_count' => $tagCount,
                'last_seen' => $appointments->sortByDesc('date')->first()?->date->toDateString(),
            ];
        });
    }

    public function getClientProfile(User $client, User $artist): array
    {
        $appointments = Appointment::where('client_id', $client->id)
            ->where('artist_id', $artist->id)
            ->orderByDesc('date')
            ->get();

        return [
            'client' => $client,
            'stats' => $this->buildStats($appointments),
            'tags' => $this->getTagsGroupedByCategory($client, $artist),
            'notes' => $this->getNotes($client, $artist),
            'appointment_notes' => $this->getAppointmentsWithNotes($client, $artist),
            'history' => $appointments,
        ];
    }

    public function buildStats(Collection $appointments): array
    {
        $completed = $appointments->where('status', 'completed');

        $nextAppointment = $appointments
            ->where('status', 'booked')
            ->where('date', '>=', now()->startOfDay())
            ->sortBy('date')
            ->first();

        return [
            'sessions' => $completed->count(),
            'total_spent' => (float) $completed->sum('price'),
            'hours_in_chair' => round($completed->sum(fn ($a) => ($a->duration_minutes ?? 0) / 60), 1),
            'next_appointment' => $nextAppointment?->date->toDateString(),
        ];
    }

    public function getTagsGroupedByCategory(User $client, User $artist): Collection
    {
        $categories = $this->getCategories($artist);

        $tags = UserTag::where('client_id', $client->id)
            ->whereIn('tag_category_id', $categories->pluck('id'))
            ->get();

        return $categories->map(function ($category) use ($tags) {
            $categoryTags = $tags->where('tag_category_id', $category->id)->values();
            if ($categoryTags->isEmpty()) {
                return null;
            }
            return [
                'category' => $category,
                'tags' => $categoryTags,
            ];
        })->filter()->values();
    }

    public function getCategories(User $artist): Collection
    {
        $categories = UserTagCategory::where('studio_user_id', $artist->id)
            ->orderBy('sort_order')
            ->get();

        if ($categories->isEmpty()) {
            \Database\Seeders\UserTagCategorySeeder::seedForUser($artist->id);
            $categories = UserTagCategory::where('studio_user_id', $artist->id)
                ->orderBy('sort_order')
                ->get();
        }

        return $categories;
    }

    public function createCategory(User $artist, string $name, string $color): UserTagCategory
    {
        $maxSort = UserTagCategory::where('studio_user_id', $artist->id)->max('sort_order') ?? -1;

        return UserTagCategory::create([
            'studio_user_id' => $artist->id,
            'name' => $name,
            'color' => $color,
            'sort_order' => $maxSort + 1,
        ]);
    }

    public function addTag(User $client, User $artist, int $categoryId, string $label): UserTag
    {
        $category = UserTagCategory::where('id', $categoryId)
            ->where('studio_user_id', $artist->id)
            ->firstOrFail();

        return UserTag::create([
            'client_id' => $client->id,
            'tag_category_id' => $category->id,
            'label' => $label,
        ]);
    }

    public function removeTag(User $client, User $artist, UserTag $tag): void
    {
        if ($tag->client_id !== $client->id) {
            abort(404);
        }

        UserTagCategory::where('id', $tag->tag_category_id)
            ->where('studio_user_id', $artist->id)
            ->firstOrFail();

        $tag->delete();
    }

    public function getSuggestions(User $artist, int $categoryId, ?string $query): Collection
    {
        $category = UserTagCategory::where('id', $categoryId)
            ->where('studio_user_id', $artist->id)
            ->firstOrFail();

        $q = UserTag::where('tag_category_id', $category->id)
            ->select('label')
            ->distinct();

        if ($query) {
            $q->where('label', 'like', '%' . $query . '%');
        }

        return $q->limit(8)->pluck('label');
    }

    public function getNotes(User $client, User $artist): Collection
    {
        return ClientNote::where('client_id', $client->id)
            ->where('studio_user_id', $artist->id)
            ->orderByDesc('created_at')
            ->get();
    }

    public function getAppointmentsWithNotes(User $client, User $artist): Collection
    {
        return Appointment::where('client_id', $client->id)
            ->where('artist_id', $artist->id)
            ->whereNotNull('notes')
            ->where('notes', '!=', '')
            ->orderByDesc('date')
            ->get();
    }

    public function addNote(User $client, User $artist, string $body): ClientNote
    {
        return ClientNote::create([
            'client_id' => $client->id,
            'studio_user_id' => $artist->id,
            'body' => $body,
        ]);
    }
}
