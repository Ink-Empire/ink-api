<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function __construct(
        private NotificationService $notificationService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $notifications = $this->notificationService->getNotifications(
            $request->user(),
            $request->integer('per_page', 20)
        );

        return response()->json($notifications->response()->getData(true));
    }

    public function unreadCount(Request $request): JsonResponse
    {
        $count = $this->notificationService->getUnreadCount($request->user());

        return response()->json(['unread_count' => $count]);
    }

    public function markRead(Request $request): JsonResponse
    {
        $user = $request->user();
        $notificationId = $request->input('notification_id');

        if ($notificationId) {
            $this->notificationService->markAsRead($user, $notificationId);
        } else {
            $this->notificationService->markAllAsRead($user);
        }

        return response()->json(['success' => true]);
    }
}
