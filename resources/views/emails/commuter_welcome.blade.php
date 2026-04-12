<x-mail::message>
    # Welcome to Nishukishe, {{ $user->name }}!

    We're excited to have you on board. Nishukishe is built exactly for commuters like you. By joining our platform,
    you've taken the first step towards a better and safer commute.

    Here are a few things you can do using Nishukishe:
    - **Search Routes**: Easily find the best public transport routes from where you are to your destination.
    - **Report Incidents**: Make your commute safer for everyone by reporting incidents such as delays, accidents, or
    rogue matatu behavior.
    - **Live Updates**: Get real-time updates and notifications about the routes you care about the most.

    Ready to start your journey?

    <x-mail::button :url="config('app.url') . '/search-routes'">
        Find your Route
    </x-mail::button>

    Thanks for being part of our community,<br>
    The Nishukishe Team
</x-mail::message>