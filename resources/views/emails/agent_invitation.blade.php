<x-mail::message>
    # You've been invited!

    You have been invited to join {{ $invitation->sacco->sacco_name }} as a Parcel Agent on Nishukishe by
    {{ $invitation->inviter->name }}.

    Click the button below to complete your registration and set up your account.

    <x-mail::button :url="config('app.frontend_url') . '/agent-signup/' . $invitation->token">
        Complete Registration
    </x-mail::button>

    If you did not expect this invitation, you can safely ignore this email.

    Thanks,<br>
    {{ config('app.name') }}
</x-mail::message>