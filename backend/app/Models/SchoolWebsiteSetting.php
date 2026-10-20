<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SchoolWebsiteSetting extends Model
{
    protected $fillable = [
        'website_name',
        'meta_description',
        'tagline',
        'logo_path',
        'favicon_path',
        'contact_email',
        'phone',
        'address',
        'about',
        'primary_color',
        'secondary_color',
        'facebook_url',
        'youtube_url',
        'instagram_url',
        'font_family',
        'footer_text',
        'content',
    ];

    protected function casts(): array
    {
        return [
            'content' => 'array',
        ];
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }
}
