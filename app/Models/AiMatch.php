<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiMatch extends Model
{
    protected $fillable = [
        'report_id',
        'found_id',
        'confidence_score',
        'attributes',
        'match_status',
        'matched_at',
    ];

    protected $casts = [
        'attributes' => 'array',
        'matched_at' => 'datetime',
    ];

    public function lostReport()
    {
        return $this->belongsTo(LostItemReport::class, 'report_id');
    }

    public function foundRecord()
    {
        return $this->belongsTo(FoundItemRecord::class, 'found_id');
    }

    public function claim()
    {
        return $this->hasOne(Claim::class, 'match_id');
    }

    public function notifications()
    {
        return $this->hasMany(Notification::class, 'match_id');
    }
}
