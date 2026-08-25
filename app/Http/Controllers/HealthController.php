<?php

namespace App\Http\Controllers;

use App\Enums\HealthStatus;
use App\Services\HealthCheckService;
use Illuminate\Http\JsonResponse;

class HealthController extends Controller
{
    public function __construct(protected HealthCheckService $health)
    {
    }

    /**
     * Public liveness. Deliberately returns no counts or identifiers, since
     * document totals are business metrics and this endpoint is unauthenticated.
     */
    public function shallow(): JsonResponse
    {
        $result = $this->health->shallow();

        return response()->json(
            ['status' => $result['status'], 'checked_at' => $result['checked_at']],
            $result['status'] === HealthStatus::CRITICAL ? 503 : 200
        );
    }

    /**
     * Full check payload. Behind the app token.
     */
    public function deep(): JsonResponse
    {
        $result = $this->health->deep();

        return response()->json(
            $result,
            $result['status'] === HealthStatus::CRITICAL ? 503 : 200
        );
    }
}
