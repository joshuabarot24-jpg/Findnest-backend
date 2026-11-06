<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class InventoryItem extends Model
{
    use SoftDeletes;
    protected $fillable = [
        'name',
        'description',
        'category',
        'quantity',
        'location',
        'status',
        'logged_by',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'logged_by');
    }
}
