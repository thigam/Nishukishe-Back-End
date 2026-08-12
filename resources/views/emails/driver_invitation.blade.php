<x-mail::message>
# You've been invited!

You have been invited to join **{{ $invitation->sacco->sacco_name }}** as a Driver on Nishukishe by {{ $invitation->inviter->name }}.

You will be assigned to vehicle **{{ $invitation->vehicle_registration }}**.

Click the button below to complete your registration, set up your password, and start tracking your shifts:

<x-mail::button :url="config('app.frontend_url') . '/driver-signup/' . $invitation->token">
Complete Driver Registration
</x-mail::button>

If you did not expect this invitation, you can safely ignore this email.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
