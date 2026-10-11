<?php

namespace App\Http\Resources;

use App\Models\Photo;
use Illuminate\Http\Request;

/**
 * Polling payload: the base photo data plus the internal id the client
 * uses as the next `after_id`. Only exposed by the updates endpoint.
 *
 * @mixin Photo
 */
class PhotoUpdateResource extends PhotoResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'cursor_id' => $this->id,
        ];
    }
}
