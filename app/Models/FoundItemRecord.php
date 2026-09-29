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
        'unclaimed_flagged_at',
        'needs_disposal_review',
        'disposal_notes',
        'disposed_at',
        'condition_on_receipt',
        'condition_notes',
        'surrender_deadline',
        'receipt_confirmed',
        'receipt_confirmed_at',
    ];

    protected $casts = [
    'photo_urls' => 'array',
    'receipt_confirmed' => 'boolean',
    'surrender_deadline' => 'datetime',
    'receipt_confirmed_at' => 'datetime',
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
