<?php

namespace App\Http\Controllers;

use App\Models\DirectionThread;
use App\Models\Sacco;
use App\Models\SaccoStage;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class SeoLinksController extends Controller
{
    public function index(): JsonResponse
    {
        // 1. Fetch 7 random Direction Threads
        $routes = DirectionThread::inRandomOrder()
            ->limit(7)
            ->get(['origin_slug', 'destination_slug'])
            ->map(function ($thread) {
                // Determine label if slugs are readable
                $origin = ucwords(str_replace('-', ' ', $thread->origin_slug));
                $destination = ucwords(str_replace('-', ' ', $thread->destination_slug));
                return [
                    'label' => "{$origin} to {$destination}",
                    'href' => "/directions/{$thread->origin_slug}/to/{$thread->destination_slug}",
                ];
            });

        // 2. Fetch 6 random/popular Saccos
        // Ideally "popular" means more routes or approved. For now, random but approved.
        $saccos = Sacco::where('is_approved', true)
            ->inRandomOrder()
            ->limit(6)
            ->get(['sacco_id', 'sacco_name', 'share_slug'])
            ->map(function ($sacco) {
                // Prefer share_slug if exists, else ID
                $slug = $sacco->share_slug ?: $sacco->sacco_id;
                // Frontend link format: /discover/[saccoId] or /safaris/sacco/[slug]
                // The task says "link existing pages". 
                // Existing SEOLinks uses /safaris/sacco/super-metro. 
                // But /discover/[saccoId] is the main profile page in the new app structure?
                // I will use /discover/{sacco_id} as it seems to be the main dynamic page.
                // Or /safaris/sacco/{share_slug} if it's for bookings.
                // Let's stick to /discover/{sacco_id} for now as it shows the profile.
    
                return [
                    'label' => $sacco->sacco_name,
                    'href' => "/discover/{$sacco->sacco_id}",
                ];
            });

        // 3. Fetch 6 random Stages
        $stages = SaccoStage::with('sacco:sacco_id,sacco_name')
            ->inRandomOrder()
            ->limit(6)
            ->get(['id', 'name', 'sacco_id'])
            ->map(function ($stage) {
                $saccoName = $stage->sacco ? $stage->sacco->sacco_name : 'Unknown Sacco';
                return [
                    'label' => "{$stage->name} ({$saccoName})",
                    'href' => "/discover/{$stage->sacco_id}/stages/{$stage->id}",
                ];
            });

        // 4. Nairobi Commute (Static or also random? User said "we can keep it simple... and only always show a random selection of 7 direction pages... add about 5 popular saccos and 6 popular stages")
        // It didn't mention changing Nairobi Commute or Regions. I'll keep them static in frontend or fetch them if I want everything dynamic.
        // For now, I only return the requested dynamic parts.

        return response()->json([
            'routes' => $routes,
            'saccos' => $saccos,
            'stages' => $stages,
        ]);
    }
}
