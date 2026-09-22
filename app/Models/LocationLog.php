<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LocationLog extends Model
{
    protected $fillable = [
        'report_id',
        'building',
        'area',
        'latitude',
        'longitude',
        'type',
    ];

    public function lostReport()
    {
        return $this->belongsTo(LostItemReport::class, 'report_id');
    }
}
