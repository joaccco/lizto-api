<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CategoryResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = [
            'id'    => $this->slug,
            'name'  => $this->name,
            'slug'  => $this->slug,
            'icon'  => $this->icon,
        ];

        if ($this->relationLoaded('children') && $this->children->isNotEmpty()) {
            $data['children'] = CategoryResource::collection($this->children);
        } elseif ($this->parent_id === null) {
            $data['children'] = [];
        }

        return $data;
    }
}
