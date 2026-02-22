<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\Request;

class ActivityLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'action',
        'description',
        'ip_address',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Helper to quickly log an activity.
     */
    public static function log(string $action, string $description, ?Request $request = null, ?int $userId = null): self
    {
        return self::create([
            'user_id'    => $userId ?? $request?->user()?->id,
            'action'     => $action,
            'description'=> $description,
            'ip_address' => $request?->ip(),
            'created_at' => now(),
        ]);
    }
}
