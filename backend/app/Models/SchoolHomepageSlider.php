<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SchoolHomepageSlider extends Model
{
    protected $fillable = [
        'title',
        'subtitle',
        'description',
        'image_path',
        'mobile_image_path',
        'button_text',
        'button_url',
        'display_order',
        'is_active',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'display_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }
}
