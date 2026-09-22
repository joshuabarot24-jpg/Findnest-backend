<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FoundItemRecord extends Model
{
    protected $fillable = [
        'admin_id',
        'item_name',
        'category',
        'description',
        'location_found',
        'date_found',
        'approx_time',
        'primary_color',
        'brand_model',
        'photo_urls',
        'photo_url',
        'storage_location',
        'status',
        'ai_description',
    ];

    protected $casts = [
    'photo_urls' => 'array',
    ];

    public function admin()
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

    public function aiMatches()
    {
        return $this->hasMany(AiMatch::class, 'found_id');
    }
}
