<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'school_id',
        'lrn',
        'first_name',
        'last_name',
        'middle_initial',
        'suffix',
        'course',
        'year_level',
        'education_level',
        'is_active',
        'otp_code',
        'otp_expires_at',
        'trust_score',
        'fcm_token',
        'privileges',
        'is_restricted',
        'restriction_reason',
        'restricted_until',
        'password_change_requested',
        'password_change_reason',
        'password_last_changed_at',
        'password_change_approved',
        'id_verified',
        'id_photo_url',
        'id_extracted_name',
        'id_extracted_school_id',
        'failed_login_attempts',
        'login_locked_until',
        'flagged_for_review',
        'flag_reason',
        'manual_override_granted',
        'restored_at',
        'identity_verification_status',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'otp_code',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'otp_expires_at' => 'datetime',
        'password' => 'hashed',
        'is_active' => 'boolean',
        'privileges' => 'array',
        'is_restricted' => 'boolean',
        'restricted_until' => 'date',
        'password_change_requested' => 'boolean',
        'password_last_changed_at' => 'datetime',
        'password_change_approved' => 'boolean',
        'id_verified' => 'boolean',
        'flagged_for_review' => 'boolean',
        'restored_at' => 'datetime',
        'override_expires_at' => 'datetime',
    ];

    // Old code and old apps still read and write school_id. It is the same value as lrn
    protected $appends = ['school_id'];

    public function getSchoolIdAttribute()
    {
        return $this->attributes['lrn'] ?? null;
    }

    public function setSchoolIdAttribute($value): void
    {
        $this->attributes['lrn'] = $value;
    }

    // The saved name is always "First Last Suffix" when the split names are filled in
    protected static function booted(): void
    {
        static::saving(function (User $user) {
            if ($user->first_name || $user->last_name) {
                // "Rick G. Gallardo Jr." : first name, middle initial with a period, last name, suffix
                $mi = trim((string) $user->middle_initial);
                $mi = $mi !== '' ? rtrim($mi, '.') . '.' : null;
                $user->name = trim(implode(' ', array_filter([$user->first_name, $mi, $user->last_name, $user->suffix])));
            }
        });
    }

    public function lostReports()
    {
        return $this->hasMany(LostItemReport::class, 'user_id');
    }

    public function claims()
    {
        return $this->hasMany(Claim::class, 'student_id');
    }

    public function notifications()
    {
        return $this->hasMany(Notification::class, 'user_id');
    }

    public function auditLogs()
    {
        return $this->hasMany(AuditLog::class, 'user_id');
    }

     // True only while the override is on AND its 24 hours have not passed
    public function hasActiveOverride(): bool
    {
        return (bool) $this->manual_override_granted
            && $this->override_expires_at
            && now()->lt(\Carbon\Carbon::parse($this->override_expires_at));
    }
}
