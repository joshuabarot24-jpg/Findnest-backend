<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryItem extends Model
{
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
