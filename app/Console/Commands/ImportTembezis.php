<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use App\Models\TembeaOperatorProfile;
use App\Models\Bookable;
use App\Models\TourEvent;
use App\Models\MediaAttachment;
use Illuminate\Support\Str;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Http;

class ImportTembezis extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tembezi:import {file : The path to the JSON file}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import Tembezis from a JSON file and match with operators';

    // Define allowed categories here
    const ALLOWED_CATEGORIES = [
        'Hiking',
        'Safari',
        'Beach',
        'Cultural',
        'Adventure',
        'City Tour',
        'Nature',
        'Wellness'
    ];

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $filePath = $this->argument('file');

        if (!File::exists($filePath)) {
            $this->error("File not found: {$filePath}");
            return 1;
        }

        $json = File::get($filePath);
        $data = json_decode($json, true);

        if (!$data) {
            $this->error("Invalid JSON format.");
            return 1;
        }

        $operators = TembeaOperatorProfile::all();
        $count = 0;

        foreach ($data as $item) {
            $operatorName = $item['operator_name'] ?? null;
            if (!$operatorName) {
                $this->warn("Skipping item without operator name: " . ($item['title'] ?? 'Unknown'));
                continue;
            }

            // Fuzzy match operator
            $bestMatch = null;
            $highestSimilarity = 0;

            foreach ($operators as $operator) {
                similar_text(strtolower($operatorName), strtolower($operator->company_name), $percent);
                if ($percent > $highestSimilarity) {
                    $highestSimilarity = $percent;
                    $bestMatch = $operator;
                }
            }

            if ($highestSimilarity < 70) { // Threshold
                $this->warn("No matching operator found for: {$operatorName} (Best match: " . ($bestMatch->company_name ?? 'None') . " - {$highestSimilarity}%)");
                continue;
            }

            $this->info("Matched '{$operatorName}' with '{$bestMatch->company_name}' ({$highestSimilarity}%)");

            // Validate Categories
            $categories = $item['categories'] ?? [];
            $validCategories = array_intersect($categories, self::ALLOWED_CATEGORIES);
            if (count($validCategories) !== count($categories)) {
                $this->warn("Some categories were filtered out for: {$item['title']}");
            }

            // Create Bookable
            $bookable = Bookable::create([
                'organizer_id' => $bestMatch->user_id,
                'type' => 'tour_event', // Correct type for tours
                'title' => $item['title'],
                'description' => $item['description'] ?? '',
                'status' => 'published',
                'currency' => 'KES',
                'service_fee_rate' => 0,
                'published_at' => now(),
                'starts_at' => isset($item['date']) ? \Carbon\Carbon::parse($item['date']) : now()->addDays(7),
                'ends_at' => isset($item['date']) ? \Carbon\Carbon::parse($item['date'])->addHours(5) : now()->addDays(7)->addHours(5),
                'metadata' => ['imported' => true],
            ]);

            // Parse Meeting Point and Destination
            $meetingPoint = $this->parsePoint($item['meeting_point'] ?? null);
            $destination = $this->parsePoint($item['destination'] ?? null);

            // Create TourEvent
            TourEvent::create([
                'bookable_id' => $bookable->id,
                'destination' => $destination ? [$destination] : [['name' => 'Nairobi']], // Wrap in array as per cast
                'meeting_point' => $meetingPoint ? [$meetingPoint] : [['name' => 'CBD']], // Wrap in array as per cast
                'categories' => array_values($validCategories),
                'duration_label' => $item['duration'] ?? '1 day',
                'marketing_copy' => $item['description'] ?? '',
                'contact_info' => [
                    'phone' => $item['whatsapp_number'] ?? $bestMatch->public_phone ?? $bestMatch->contact_phone,
                    'email' => $item['email_address'] ?? null,
                    'instagram' => $item['ig_account'] ?? null,
                    'whatsapp' => $item['whatsapp_number'] ?? null,
                ],
            ]);

            // Add Images (Download and Store)
            if (isset($item['images']) && is_array($item['images'])) {
                foreach ($item['images'] as $index => $imageUrl) {
                    try {
                        $this->info("Downloading image: $imageUrl");

                        $response = Http::withHeaders([
                            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36'
                        ])->timeout(30)->withoutVerifying()->get($imageUrl);

                        if ($response->successful()) {
                            $contents = $response->body();
                            if (strlen($contents) < 1000) {
                                $this->warn("Image content too small (" . strlen($contents) . " bytes). Might be an error page.");
                            }

                            $tempPath = tempnam(sys_get_temp_dir(), 'tembezi_img');
                            file_put_contents($tempPath, $contents);

                            // Upload to external server
                            $this->info("Uploading to image server...");
                            $uploadResponse = Http::attach(
                                'image',
                                file_get_contents($tempPath),
                                basename($imageUrl) . '.jpg'
                            )->post('https://images.nishukishe.com/upload.php');

                            unlink($tempPath); // Clean up temp file

                            if ($uploadResponse->successful()) {
                                $uploadData = $uploadResponse->json();
                                if (isset($uploadData['status']) && $uploadData['status'] === 'success' && isset($uploadData['url'])) {
                                    $finalUrl = $uploadData['url'];

                                    MediaAttachment::create([
                                        'bookable_id' => $bookable->id,
                                        'file_path' => $finalUrl, // Store URL in file_path for external images if that's the convention, or just rely on url
                                        'file_type' => 'image',
                                        'position' => $index,
                                        'is_external' => true,
                                        'url' => $finalUrl,
                                    ]);
                                    $this->info("Image uploaded and attached: $finalUrl");
                                } else {
                                    $this->warn("Image upload failed: " . json_encode($uploadData));
                                }
                            } else {
                                $this->warn("Image upload HTTP error: " . $uploadResponse->status());
                            }
                        } else {
                            $this->warn("Failed to download image (HTTP {$response->status()}): $imageUrl");
                        }
                    } catch (\Exception $e) {
                        $this->warn("Failed to download image: $imageUrl. Error: " . $e->getMessage());
                    }
                }
            }

            // Create Ticket Tier (Price)
            if (isset($item['price']) && is_numeric($item['price'])) {
                $bookable->ticketTiers()->create([
                    'name' => 'Standard',
                    'description' => 'Standard Ticket',
                    'price' => $item['price'],
                    'currency' => 'KES',
                    'total_quantity' => 100, // Default
                    'remaining_quantity' => 100,
                    'min_per_order' => 1,
                    'max_per_order' => 10,
                    'sales_start' => now(),
                ]);
            }

            // Update TourEvent with checkout_type and metadata
            $tourEvent = TourEvent::where('bookable_id', $bookable->id)->first();
            if ($tourEvent) {
                $metadata = $tourEvent->metadata ?? [];

                // Checkout Type
                $checkoutType = $item['checkout_type'] ?? (isset($item['external_link']) ? 'external' : 'tembea');
                $tourEvent->checkout_type = $checkoutType;
                $metadata['checkout_type'] = $checkoutType;

                // External Link
                if ($checkoutType === 'external' && isset($item['external_link'])) {
                    $metadata['external_link'] = $item['external_link'];
                }

                // Other properties
                if (isset($item['inclusions']))
                    $metadata['inclusions'] = $item['inclusions'];
                if (isset($item['requirements']))
                    $metadata['requirements'] = $item['requirements'];
                if (isset($item['itinerary']))
                    $metadata['itinerary'] = $item['itinerary'];

                $tourEvent->metadata = $metadata;
                $tourEvent->save();
            }

            $this->info("Created Tembezi: {$item['title']}");
            $count++;
        }

        $this->info("Import completed. Created {$count} Tembezis.");
        return 0;
    }

    private function parsePoint($data)
    {
        if (!$data)
            return null;

        if (is_string($data)) {
            return ['name' => $data];
        }

        if (is_array($data)) {
            return [
                'name' => $data['name'] ?? 'Unknown',
                'coordinates' => [
                    'lat' => $data['lat'] ?? null,
                    'lng' => $data['lng'] ?? null,
                ]
            ];
        }

        return null;
    }
}
