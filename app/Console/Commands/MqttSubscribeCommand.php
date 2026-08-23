<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use PhpMqtt\Client\MqttClient;
use PhpMqtt\Client\ConnectionSettings;
use App\Models\Vehicle;
use App\Models\VehicleLiveLocation;
use App\Models\LocationPing;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;
use Throwable;

class MqttSubscribeCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mqtt:subscribe';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Listen to incoming Bonyo IoT telemetry stream over MQTT';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $server   = config('services.mqtt.host', '46.225.62.35');
        $port     = (int) config('services.mqtt.port', 1883);
        $username = config('services.mqtt.username');
        $password = config('services.mqtt.password');
        $prefix   = config('services.mqtt.topic_prefix', 'staging/matatu/');
        $clientId = 'laravel_ingestor_' . uniqid();

        $this->info("Connecting to MQTT Broker at {$server}:{$port}...");

        while (true) {
            try {
                $mqtt = new MqttClient($server, $port, $clientId);
                $settings = (new ConnectionSettings())
                    ->setKeepAliveInterval(60)
                    ->setLastWillTopic('backend/ingestor/status')
                    ->setLastWillMessage('offline');

                if ($username && $password) {
                    $settings->setUsername($username)->setPassword($password);
                }

                $mqtt->connect($settings, true);
                $this->info("Connected successfully! Subscribing to {$prefix}+/telemetry");

                // Subscribe to all bonyo telemetry topics (e.g. staging/matatu/+/telemetry)
                $mqtt->subscribe($prefix . '+/telemetry', function (string $topic, string $message) {
                    $this->processTelemetry($topic, $message);
                }, 0);

                $mqtt->loop(true);
            } catch (Throwable $e) {
                $this->error("MQTT connection lost or error occurred: " . $e->getMessage());
                Log::error("MQTT Worker Error: " . $e->getMessage(), ['exception' => $e]);
                $this->info("Reconnecting in 5 seconds...");
                sleep(5);
            }
        }
    }

    /**
     * Process an incoming telemetry payload from a Bonyo hardware node.
     */
    private function processTelemetry(string $topic, string $message)
    {
        try {
            $payload = json_decode($message, true);
            if (!$payload || !isset($payload['device_id'])) {
                $this->warn("Received invalid MQTT payload on topic [{$topic}]: {$message}");
                return;
            }

            $deviceId   = $payload['device_id'];
            $lat        = (float) ($payload['lat'] ?? 0.0);
            $lng        = (float) ($payload['lng'] ?? 0.0);
            $speed      = (float) ($payload['speed'] ?? 0.0);
            $isFull     = (bool)  ($payload['is_full'] ?? false);
            $airtimeBal = isset($payload['airtime_bal']) ? (float) $payload['airtime_bal'] : null;

            $this->info("Received telemetry from [{$deviceId}] => Lat: {$lat}, Lng: {$lng}, Speed: {$speed} km/h, Full: " . ($isFull ? 'YES' : 'NO'));

            // 1. Fast Redis Geo-spatial Indexing (for instant commuter queries)
            try {
                Redis::geoadd('matatus:live_geo', $lng, $lat, $deviceId);
                Redis::hset("matatu:status:{$deviceId}", [
                    'lat'         => $lat,
                    'lng'         => $lng,
                    'speed'       => $speed,
                    'is_full'     => $isFull ? 1 : 0,
                    'airtime_bal' => $airtimeBal ?? '',
                    'updated_at'  => now()->toIso8601String(),
                ]);
            } catch (Throwable $redisEx) {
                Log::warning("Redis update failed in MQTT worker: " . $redisEx->getMessage());
            }

            // 2. Lookup Vehicle assigned to this Bonyo device_id
            $vehicle = Vehicle::where('hardware_device_id', $deviceId)->first();
            if ($vehicle) {
                VehicleLiveLocation::updateOrCreate(
                    ['vehicle_id' => $vehicle->id],
                    [
                        'driver_id'       => $vehicle->driver_id,
                        'sacco_route_id'  => $vehicle->route_id,
                        'lat'             => $lat,
                        'lng'             => $lng,
                        'speed_kmh'       => $speed,
                        'location_source' => 'hardware_tracker',
                        'is_active'       => true,
                        'is_full'         => $isFull,
                        'recorded_at'     => now(),
                    ]
                );
            } else {
                $this->line("Notice: Device [{$deviceId}] is unassigned to any vehicle.");
            }

            // 3. Log ping history in location_pings table
            LocationPing::create([
                'device_id'   => $deviceId,
                'lat'         => $lat,
                'lng'         => $lng,
                'speed_kmh'   => $speed,
                'recorded_at' => now(),
                'created_at'  => now(),
            ]);

        } catch (Throwable $e) {
            $this->error("Failed to process telemetry payload: " . $e->getMessage());
            Log::error("MQTT Payload Processing Error: " . $e->getMessage());
        }
    }
}
