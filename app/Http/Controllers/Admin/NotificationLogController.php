<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\PaginationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

        return response()->json([
            'data' => $items->map(function (NotificationLogItem $item) use ($recipients) {
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
                    'created_at' => $item->created_at,
                ];
            })->values(),
            'total' => $total,
        ]);
    }
}
