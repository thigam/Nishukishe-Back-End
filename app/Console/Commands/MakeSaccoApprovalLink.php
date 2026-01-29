<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class MakeSaccoApprovalLink extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'make:sacco-approval-link {email}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate an approval link for a Sacco manager to complete registration';

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

        // Generate the token as done in UserApprovedEmail
        $token = $user->createToken('sacco_admin_token')->plainTextToken;

        // Construct the URL
        $frontendUrl = config('app.frontend_url') . '/saccos/signup/first-sacco-manager' .
            '?email=' . urlencode($email) .
            '&token=' . urlencode($token);

        $this->info("Approval Link for {$email}:");
        $this->line($frontendUrl);
        $this->info("(This link contains a secure token. Send it only to the user.)");

        return 0;
    }
}
