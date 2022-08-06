<?php

namespace App\Models;

use Database\Factories\ShiftFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @method ShiftFactory factory()
 */
class Shift extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var string[]
     */
    protected $fillable = [
        'name',
        'location_id',
        'day_monday',
        'day_tuesday',
        'day_wednesday',
        'day_thursday',
        'day_friday',
        'day_saturday',
        'day_sunday',
        'is_enabled',
    ];

    protected $casts = [
        'day_monday'    => 'boolean',
        'day_tuesday'   => 'boolean',
        'day_wednesday' => 'boolean',
        'day_thursday'  => 'boolean',
        'day_friday'    => 'boolean',
        'day_saturday'  => 'boolean',
        'day_sunday'    => 'boolean',
        'is_enabled'    => 'boolean',
    ];

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}
