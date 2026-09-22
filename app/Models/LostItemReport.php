<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LostItemReport extends Model
{
    protected $fillable = [
        'user_id',
        'item_name',
        'category',
        'description',
        'location_lost',
        'date_lost',
        'approx_time',
        'primary_color',
        'brand_model',
        'photo_urls',
        'photo_url',
        'status',
        'ai_description',
    ];

    protected $casts = [
    'photo_urls' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function aiMatches()
    {
        return $this->hasMany(AiMatch::class, 'report_id');
    }

    public function locationLogs()
    {
        return $this->hasMany(LocationLog::class, 'report_id');
    }

}
