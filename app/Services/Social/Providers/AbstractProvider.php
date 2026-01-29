<?php

namespace App\Services\Social\Providers;

use Carbon\CarbonInterface;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

abstract class AbstractProvider implements SocialPlatformProvider
{
    public function __construct(protected readonly array $config = [])
    {
    }

    public function fetchSnapshot(CarbonInterface $since): array
    {
        try {
            return $this->doFetch($since);
        } catch (\Throwable $exception) {
            Log::warning(sprintf('Falling back to stub for %s provider: %s', $this->platform(), $exception->getMessage()), [
                'exception' => $exception,
            ]);

            return $this->loadStub();
        }
    }

    /**
     * Perform the live API call.
     */
    abstract protected function doFetch(CarbonInterface $since): array;

    /**
     * Load stub data from storage/social/stubs/{platform}.json.
     */
    protected function loadStub(): array
    {
        $path = $this->config['stub_path'] ?? (config('social.stubs_path') . DIRECTORY_SEPARATOR . $this->platform() . '.json');

        try {
            $contents = File::get($path);
        } catch (FileNotFoundException) {
            return [
                'collected_at' => now()->toIso8601String(),
                'account' => [
                    'external_id' => $this->platform() . '-stub',
                    'display_name' => ucfirst($this->platform()) . ' (stub)',
                ],
                'metrics' => [
                    'followers' => 0,
                    'post_count' => 0,
                ],
                'posts' => [],
            ];
        }

        $decoded = json_decode($contents, true);

        if (! is_array($decoded)) {
            return [
                'collected_at' => now()->toIso8601String(),
                'account' => [
                    'external_id' => $this->platform() . '-stub',
                    'display_name' => ucfirst($this->platform()) . ' (invalid stub)',
                ],
                'metrics' => [
                    'followers' => 0,
                    'post_count' => 0,
                ],
                'posts' => [],
            ];
        }

        return $decoded;
    }

    protected function arrayInt(array $data, string $key, int $default = 0): int
    {
        $value = Arr::get($data, $key);

        return is_numeric($value) ? (int) $value : $default;
    }

    protected function arrayFloat(array $data, string $key, float $default = 0.0): float
    {
        $value = Arr::get($data, $key);

        return is_numeric($value) ? (float) $value : $default;
    }
}
