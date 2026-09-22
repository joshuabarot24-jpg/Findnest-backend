<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OwnershipQuestion extends Model
{
    protected $fillable = [
        'claim_id',
        'question_text',
        'option_a',
        'option_b',
        'option_c',
        'option_d',
        'correct_option',
        'student_answer',
    ];

    public function claim()
    {
        return $this->belongsTo(Claim::class);
    }
}
