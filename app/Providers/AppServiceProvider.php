<?php

namespace App\Providers;

use App\Services\Social\InteractionScorer;
use App\Services\Social\InteractionWeightRepository;
use App\Services\Social\SocialIngestionManager;
use App\Services\Social\Providers\SocialPlatformProvider;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\HttpFoundation\Cookie as SymfonyCookie;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(InteractionWeightRepository::class, function ($app) {
            /** @var CacheRepository $cache */
            $cache = $app->make('cache.store');

            return new InteractionWeightRepository($cache);
        });

        $this->app->singleton(InteractionScorer::class, function ($app) {
            return new InteractionScorer($app->make(InteractionWeightRepository::class));
        });

        $this->app->singleton(SocialIngestionManager::class, function ($app) {
            $platforms = config('social.platforms', []);
            $providers = [];

            foreach ($platforms as $key => $options) {
                $class = $options['provider'] ?? null;
                if (!$class || !class_exists($class)) {
                    Log::warning('Unknown social provider class configured.', ['platform' => $key, 'class' => $class]);
                    continue;
                }

                $config = $options['config'] ?? [];
                $config['stub_path'] = $config['stub_path'] ?? (config('social.stubs_path') . DIRECTORY_SEPARATOR . $key . '.json');

                /** @var SocialPlatformProvider $provider */
                $provider = $app->make($class, ['config' => $config]);
                $providers[] = $provider;
            }

            return new SocialIngestionManager($app->make('db'), $app->make(InteractionScorer::class), $providers);
        });
    }

    public function boot()
    {
        Cookie::macro('create', function ($name, $value = null, $minutes = 0, $path = null, $domain = null, $secure = true, $httpOnly = true, $sameSite = 'None') {
            return new SymfonyCookie($name, $value, $minutes, $path, $domain, $secure, $httpOnly, false, $sameSite);
        });
    }
}

