<x-mail::message>
    # Hello {{ $user->name }},

    Great news! The route you suggested has been added to our platform.

    Thank you for contributing to opening up the public transport industry in East Africa. Your feedback helps us make
    commuting easier for everyone in Kenya.

    <x-mail::button :url="config('app.url')">
        Visit Nishukishe
    </x-mail::button>

    Best regards,<br>
    The Nishukishe Team
</x-mail::message>