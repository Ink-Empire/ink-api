<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\PaginationService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Spatie\NotificationLog\Models\NotificationLogItem;

/**
 * Every notification the platform has sent, so an admin can answer "did this
 * person get their email, and when".
 *
 * The log records that a notification went out, not what it said. Bodies are
 * deliberately not stored: they carry temp passwords and personal details, and
 * keeping them would undo the same decision made about artists' calendar event
 * contents. Telescope shows bodies locally.
 */
class NotificationLogController extends Controller
{
    public function __construct(
        private PaginationService $paginationService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $pagination = $this->paginationService->extractParams($request);
        $sort = $request->input('sort', 'id');
        $order = $request->input('order', 'desc');
        $filter = $request->input('filter', []);

        if (is_string($filter)) {
            $filter = json_decode($filter, true) ?? [];
        }

        $query = NotificationLogItem::query();

        // Searching by the person is the point of the screen, and the log only
        // holds their id, so the address is resolved to ids first.
        if (! empty($filter['q'])) {
            $search = $filter['q'];

            $userIds = User::where('email', 'like', "%{$search}%")
                ->orWhere('name', 'like', "%{$search}%")
                ->pluck('id');

            $query->where(function ($q) use ($search, $userIds) {
                $q->where('notification_type', 'like', "%{$search}%")
                    ->orWhereIn('notifiable_id', $userIds);
            });
        }

        if (! empty($filter['channel'])) {
            $query->where('channel', $filter['channel']);
        }

        if (! empty($filter['notification_type'])) {
            $query->where('notification_type', $filter['notification_type']);
        }

        if (! empty($filter['notifiable_id'])) {
            $query->where('notifiable_id', $filter['notifiable_id']);
        }

        $sortable = ['id', 'created_at', 'notification_type', 'channel'];

        if (! in_array($sort, $sortable, true)) {
            $sort = 'id';
        }

        $query->orderBy($sort, $order === 'asc' ? 'asc' : 'desc');

        $total = $query->count();

        $items = $this->paginationService
            ->applyToQuery($query, $pagination['offset'], $pagination['per_page'])
            ->get();

        // Loaded in one query rather than per row. The notifiable may be gone,
        // in which case the row still shows what was sent and when.
        $recipients = User::whereIn('id', $items->pluck('notifiable_id')->filter()->unique())
            ->get(['id', 'name', 'email'])
            ->keyBy('id');

        $telescopeUrls = $this->telescopeUrls($items, $recipients);

        return response()->json([
            'data' => $items->map(function (NotificationLogItem $item) use ($recipients, $telescopeUrls) {
                $recipient = $recipients->get($item->notifiable_id);

                return [
                    'id' => $item->id,
                    'notification_type' => class_basename($item->notification_type),
                    'notification_class' => $item->notification_type,
                    'channel' => $item->channel,
                    'notifiable_id' => $item->notifiable_id,
                    'recipient_name' => $recipient?->name,
                    'recipient_email' => $recipient?->email,
                    'extra' => $item->extra,
                    'telescope_url' => $telescopeUrls[$item->id] ?? null,
                    'created_at' => $item->created_at,
                ];
            })->values(),
            'total' => $total,
        ]);
    }

    /**
     * Links from log rows to the matching Telescope mail entry, keyed by log id.
     *
     * The two are separate systems with no shared key, but Telescope tags every
     * mail entry with the recipient's address and both records are written in
     * the same second, so the pair identifies the entry.
     *
     * A row gets no link when Telescope is off, when it has pruned the entry,
     * or when the notification did not go out by mail. That is what makes this
     * safe in production, where Telescope is disabled: the query is skipped and
     * every link comes back null rather than pointing at a dead page.
     *
     * @return array<int, string>
     */
    private function telescopeUrls(Collection $items, Collection $recipients): array
    {
        if (! config('telescope.enabled')) {
            return [];
        }

        $mailItems = $items->filter(fn (NotificationLogItem $item) => $item->channel === 'mail'
            && $recipients->has($item->notifiable_id)
        );

        if ($mailItems->isEmpty()) {
            return [];
        }

        $emails = $mailItems->map(fn ($item) => $recipients->get($item->notifiable_id)->email)->unique()->values();
        $times = $mailItems->map(fn ($item) => $item->created_at->format('Y-m-d H:i:s'))->unique()->values();

        try {
            $entries = DB::table('telescope_entries_tags')
                ->join('telescope_entries', 'telescope_entries.uuid', '=', 'telescope_entries_tags.entry_uuid')
                ->where('telescope_entries.type', 'mail')
                ->whereIn('telescope_entries_tags.tag', $emails)
                ->whereIn('telescope_entries.created_at', $times)
                ->get(['telescope_entries.uuid', 'telescope_entries.created_at', 'telescope_entries_tags.tag']);
        } catch (\Throwable $e) {
            // Telescope's tables may not exist wherever this is running. A
            // missing link is not worth failing the screen over.
            return [];
        }

        $byEmailAndTime = $entries->keyBy(
            fn ($entry) => $entry->tag.'|'.Carbon::parse($entry->created_at)->format('Y-m-d H:i:s')
        );

        return $mailItems->mapWithKeys(function (NotificationLogItem $item) use ($recipients, $byEmailAndTime) {
            $key = $recipients->get($item->notifiable_id)->email.'|'.$item->created_at->format('Y-m-d H:i:s');
            $entry = $byEmailAndTime->get($key);

            return [$item->id => $entry ? url('/telescope/mail/'.$entry->uuid) : null];
        })->filter()->all();
    }
}
