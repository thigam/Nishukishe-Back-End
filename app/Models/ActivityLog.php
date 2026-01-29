<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

class ActivityLog extends Model
{
    protected $fillable = [
        'user_id',
        'session_id',
        'ip_address',
        'device',
        'browser',
        'started_at',
        'ended_at',
        'duration_seconds',
        'urls_visited',
        'routes_searched',
    ];

    protected $casts = [
        'urls_visited' => 'array',
        'routes_searched' => 'array',
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
    ];

    public static function current(): ?self
    {
        return self::where('session_id', session()->getId())
            ->latest()
            ->first();
    }

    public static function startSession(): self
    {
        return self::firstOrCreate(
            ['session_id' => session()->getId()],
            [
                'user_id'   => Auth::id(),
                'ip_address'=> request()->ip(),
                'device'    => request()->header('User-Agent'),
                'started_at'=> now(),
            ]
        );
    }

    public function addUrl(string $url): void
    {
        $urls = $this->urls_visited ?? [];
        if (!in_array($url, $urls)) {
            $urls[] = $url;
            $this->urls_visited = $urls;
            $this->save();
        }
    }

    public function addRoute(string $route): void
    {
        $routes = $this->routes_searched ?? [];
        $routes[] = $route; // can allow duplicates
        $this->routes_searched = $routes;
        $this->save();
    }

    public function endSession(): void
    {
        $this->ended_at = now();
        $this->duration_seconds = $this->ended_at->diffInSeconds($this->started_at);
        $this->save();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
