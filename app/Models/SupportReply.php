<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupportReply extends Model
{
    protected $fillable = [
        'support_message_id',
        'user_id',
        'sender_type',
        'message',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
