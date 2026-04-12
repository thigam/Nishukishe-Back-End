<x-mail::message>
    # Daily Nishukishe Summary

    Here is the activity summary for today.

    <x-mail::panel>
        ### General Engagement
        - **Total Searches:** {{ $stats['searches'] }}
        - **Unique Visitors:** {{ $stats['unique_visitors'] }}
        - **Incidents Reported:** {{ $stats['incidents'] }}
    </x-mail::panel>

    <x-mail::panel>
        ### Sign Ups Today
        - **Commuters:** {{ $stats['signups']['commuters'] }}
        - **Sacco Managers:** {{ $stats['signups']['sacco_managers'] }}
        - **Service Persons:** {{ $stats['signups']['service_persons'] }}
        - **Others:** {{ $stats['signups']['others'] }}
    </x-mail::panel>

    <x-mail::panel>
        ### Operational Activity
        - **Sacco Managers Logged In:** {{ $stats['managers_logged_in'] }}
        - **Service Persons Logged In:** {{ $stats['service_persons_logged_in'] }}
        - **New Sacco Routes Created:** {{ $stats['routes_created'] }}
        - **Active Vehicles Today:** {{ $stats['active_vehicles'] }}
        - **New Saccos Registered:** {{ $stats['new_saccos'] }}
    </x-mail::panel>

    @if(count($stats['super_admin_activity']) > 0)
        <x-mail::panel>
            ### Super Admin Activity
            Users accessed Super Admin routes today:
            @foreach($stats['super_admin_activity'] as $activity)
                - **{{ $activity['user'] }}** accessed `{{ $activity['url'] }}`
            @endforeach
        </x-mail::panel>
    @else
        **No Super Admin Activity Today.**
    @endif

    Thanks,<br>
    Nishukishe System
</x-mail::message>