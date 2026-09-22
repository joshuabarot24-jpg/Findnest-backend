<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Claim extends Model
{
    protected $fillable = [
        'match_id',
        'student_id',
        'admin_id',
        'proof_description',
        'proof_photo_url',
        'photo_similarity_score',
        'claim_status',
        'admin_notes',
        'claimed_at',
        'pickup_deadline',
        'collected_at',
        'reminder_sent',
        'appeal_message',
        'appeal_status',
        'appeal_submitted_at',
        'appeal_photo_url',
        'proof_photo_urls' => 'array',
    ];

    protected $casts = [
        'claimed_at' => 'datetime',
        'pickup_deadline' => 'date',
        'collected_at' => 'datetime',
        'reminder_sent' => 'boolean',
        'appeal_submitted_at' => 'datetime',
        'proof_photo_urls' => 'array',
    ];

    public function match()
    {
        return $this->belongsTo(AiMatch::class, 'match_id');
    }

    public function student()
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function admin()
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

    public function ownershipQuestions()
    {
        return $this->hasMany(OwnershipQuestion::class, 'claim_id');
    }
}
