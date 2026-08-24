<?php

declare(strict_types=1);

namespace Reiarseni\SanctumRefreshToken\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Reiarseni\SanctumRefreshToken\ValueObjects\Session;

/**
 * Serialises a Session for an API response.
 *
 * It is built over the Session value object rather than over the Eloquent row,
 * which is what makes it structurally impossible for this resource to leak a
 * token hash, a metadata hash or a row identifier: the value object never
 * carried them in the first place.
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
