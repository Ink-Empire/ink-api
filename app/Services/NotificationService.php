<?php

namespace App\Services;

use App\Http\Resources\NotificationResource;
use App\Models\User;

class NotificationService
{
    public function getNotifications(User $user, int $perPage = 20)
    {
        $notifications = $user->notifications()->paginate($perPage);

        return NotificationResource::collection($notifications);
    }

    public function getUnreadCount(User $user): int
    {
        return $user->unreadNotifications()->count();
    }

    public function markAllAsRead(User $user): void
    {
        $user->unreadNotifications->markAsRead();
    }

    public function markAsRead(User $user, string $notificationId): void
    {
        $notification = $user->notifications()->where('id', $notificationId)->first();

        if ($notification) {
            $notification->markAsRead();
        }
    }
}
