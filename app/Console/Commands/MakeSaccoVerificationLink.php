<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\URL;

class MakeSaccoVerificationLink extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'make:sacco-verification-link {email}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate a verification link for a Sacco manager';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $email = $this->argument('email');
        $user = User::where('email', $email)->first();

        if (!$user) {
            $this->error("User with email {$email} not found.");
            return 1;
        }

        if ($user->hasVerifiedEmail()) {
            $this->info("User {$email} has already verified their email.");
            return 0;
        }

        // Generate a signed URL valid for 24 hours
        $temporaryUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addHours(24),
            ['id' => $user->id, 'hash' => sha1($user->email)]
        );

        // Parse the URL to construct the frontend link
        $parsed = parse_url($temporaryUrl);
        $query = [];
        if (isset($parsed['query'])) {
            parse_str($parsed['query'], $query);
        }

        $frontendUrl = rtrim(config('app.frontend_url'), '/');
        $verificationUrl = $frontendUrl . '/verify-email/' . $user->id . '/' . sha1($user->email)
            . '?expires=' . ($query['expires'] ?? '')
            . '&signature=' . ($query['signature'] ?? '');

        $this->info("Verification Link for {$email}:");
        $this->line($verificationUrl);
        $this->info("(Valid for 24 hours)");

        return 0;
    }
}
