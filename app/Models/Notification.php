<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Notification extends Model
{
    protected $fillable = [
        'user_id',
        'match_id',
        'title',
        'message',
        'type',
        'is_read',
        'sent_at',
    ];

    protected $appends = ['report_type'];

    protected $casts = [
        'is_read' => 'boolean',
        'sent_at' => 'datetime',
    ];

    public function getReportTypeAttribute(): ?string
    {
        $found = [
            'Found Item Report Submitted',
            'Item Surrendered',
            'Item Returned to Owner',
            'Found Item Report Expired',
            'Your Found Item May Have an Owner',
            'Your Found Item Was Claimed',
        ];

        $lost = [
            'Lost Item Report Submitted',
            'AI Match Found!',
            'Possible Match Found!',
            'Claim Submitted',
            'Claim Under Review',
            'Claim Approved!',
            'Claim Rejected',
            'Appeal Submitted',
            'Appeal Approved!',
            'Appeal Reviewed',
            'Item Collected',
            'Pickup Reminder',
            'Claim Abandoned',
        ];

        if (in_array($this->title, $found, true)) {
            return 'found';
        }

        if (in_array($this->title, $lost, true)) {
            return 'lost';
        }

        return null;
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function match()
    {
        return $this->belongsTo(AiMatch::class, 'match_id');
    }
}
