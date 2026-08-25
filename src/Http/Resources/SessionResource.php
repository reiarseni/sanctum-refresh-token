<?php

declare(strict_types=1);

namespace Reiarseni\SanctumRefreshToken\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Reiarseni\SanctumRefreshToken\ValueObjects\Session;

/**
 * Built over the Session value object rather than the Eloquent row, which makes
 * it structurally impossible to leak a token hash, a metadata hash or a row id:
 * the value object never carried them.
 *
 * @property-read Session $resource
 */
class SessionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Session $session */
        $session = $this->resource;

        return $session->toArray();
    }
}
