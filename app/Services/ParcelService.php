<?php

namespace App\Services;

use App\Models\Parcel;
use Illuminate\Support\Str;

class ParcelService
{
    public function create(array $data): array
    {
        $data['package_id'] = (string) Str::uuid();
        $parcel = Parcel::create($data);
        $driverCode = random_int(10000, 99999);
        $parcel->verificationCode()->create(['driver_code' => $driverCode]);
        $parcel->events()->create([
            'action' => 'created',
            'location' => $data['location'] ?? null,
        ]);
        return [$parcel, $driverCode];
    }

    public function lock(Parcel $parcel, ?string $location = null): Parcel
    {
        $parcel->update(['status' => 'locked']);
        $parcel->events()->create([
            'action' => 'locked',
            'location' => $location,
        ]);
        return $parcel;
    }

    public function markInTransit(Parcel $parcel, ?string $location = null): Parcel
    {
        $parcel->update(['status' => 'in_transit']);
        $parcel->events()->create([
            'action' => 'in_transit',
            'location' => $location,
        ]);
        return $parcel;
    }

    public function markOffloaded(Parcel $parcel, ?string $location = null): Parcel
    {
        $parcel->update(['status' => 'offloaded']);
        $parcel->events()->create([
            'action' => 'offloaded',
            'location' => $location,
        ]);
        return $parcel;
    }

    public function confirmHandover(Parcel $parcel, ?string $location = null): Parcel
    {
        $parcel->update(['status' => 'delivered']);
        $parcel->events()->create([
            'action' => 'delivered',
            'location' => $location,
        ]);
        return $parcel;
    }
}
