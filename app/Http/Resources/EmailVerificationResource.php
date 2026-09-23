<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Verification state for an account that is not signed in yet.
 *
 * Carries nothing beyond what the caller already proved they know.
 */
class EmailVerificationResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'email' => $this->email,
            'requires_verification' => !$this->hasVerifiedEmail(),
        ];
    }
}
