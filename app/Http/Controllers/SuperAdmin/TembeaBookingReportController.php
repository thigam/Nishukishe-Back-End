<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\UserRole;
use App\Services\MpesaCostService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class TembeaBookingReportController extends Controller
{
    private const INTERVAL_OPTIONS = ['day', 'week', 'month', 'year'];

    public function __construct(private readonly MpesaCostService $mpesaCostService)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $interval = $this->resolveInterval($request->query('interval'));

        $bookings = Booking::query()
            ->with([
                'bookable.organizer.tembeaOperatorProfile',
                'bookable.organizer',
                'bookable',
                'payments',
            ])
            ->whereHas('bookable', function ($query) {
                $query->where('type', 'tour_event')
                    ->whereHas('organizer', function ($organizerQuery) {
                        $organizerQuery->where('role', UserRole::TEMBEA);
                    });
            })
            ->orderByDesc('created_at')
            ->get();

        $data = [
            'bookings' => $bookings->map(fn (Booking $booking) => $this->transformBooking($booking))->values()->all(),
            'operators' => $this->groupByOperator($bookings),
            'tembezi' => $this->groupByTembezi($bookings),
            'financials' => $this->buildFinancialSummary($bookings, $interval),
            'charges' => $this->buildChargesSummary(),
        ];

        return response()->json([
            'data' => $data,
            'meta' => [
                'generated_at' => Carbon::now()->toIso8601String(),
                'interval' => $interval,
                'total_bookings' => $bookings->count(),
            ],
        ]);
    }

    private function resolveInterval(mixed $value): string
    {
        if (is_string($value) && in_array($value, self::INTERVAL_OPTIONS, true)) {
            return $value;
        }

        return 'month';
    }

    private function transformBooking(Booking $booking): array
    {
        $bookable = $booking->bookable;
        $organizer = $bookable?->organizer;
        $profile = $organizer?->tembeaOperatorProfile;

        return [
            'id' => $booking->id,
            'reference' => $booking->reference,
            'currency' => $booking->currency,
            'total_amount' => (float) ($booking->total_amount ?? 0),
            'service_fee_amount' => (float) ($booking->service_fee_amount ?? 0),
            'net_amount' => $this->calculateNetAmount($booking),
            'status' => $booking->status,
            'payment_status' => $booking->payment_status,
            'paid_at' => $booking->paid_at?->toIso8601String(),
            'created_at' => $booking->created_at?->toIso8601String(),
            'bookable' => $bookable ? [
                'id' => $bookable->id,
                'title' => $bookable->title,
                'type' => $bookable->type,
            ] : null,
            'operator' => $organizer ? [
                'id' => $organizer->id,
                'name' => $organizer->name,
                'email' => $organizer->email,
                'phone' => $organizer->phone,
                'company_name' => $profile?->company_name,
                'contact_name' => $profile?->contact_name,
                'contact_email' => $profile?->contact_email,
                'contact_phone' => $profile?->contact_phone,
            ] : null,
            'payments' => $booking->payments->map(function (Payment $payment) {
                return [
                    'id' => $payment->id,
                    'provider' => $payment->provider,
                    'status' => $payment->status,
                    'amount' => (float) ($payment->amount ?? 0),
                    'fee_amount' => $payment->fee_amount !== null ? (float) $payment->fee_amount : null,
                    'processed_at' => $payment->processed_at?->toIso8601String(),
                ];
            })->values()->all(),
        ];
    }

    private function groupByOperator(Collection $bookings): array
    {
        return $bookings
            ->groupBy(function (Booking $booking) {
                $organizerId = $booking->bookable?->organizer?->id;

                return $organizerId ? (string) $organizerId : 'unassigned';
            })
            ->map(function (Collection $group) {
                $sample = $group->first();
                $bookable = $sample?->bookable;
                $organizer = $bookable?->organizer;
                $profile = $organizer?->tembeaOperatorProfile;
                $paid = $group->filter(fn (Booking $booking) => $booking->payment_status === 'paid');

                $lastDate = $group
                    ->map(fn (Booking $booking) => $this->resolveBookingDate($booking))
                    ->reduce(function (?Carbon $carry, Carbon $date) {
                        if ($carry === null || $date->greaterThan($carry)) {
                            return $date;
                        }

                        return $carry;
                    });

                return [
                    'operator_id' => $organizer?->id,
                    'company_name' => $profile?->company_name ?? $organizer?->name,
                    'contact_name' => $profile?->contact_name ?? $organizer?->name,
                    'contact_email' => $profile?->contact_email ?? $organizer?->email,
                    'contact_phone' => $profile?->contact_phone ?? $organizer?->phone,
                    'total_bookings' => $group->count(),
                    'paid_bookings' => $paid->count(),
                    'total_amount' => round($group->sum(fn (Booking $booking) => (float) ($booking->total_amount ?? 0)), 2),
                    'service_fee_amount' => round($group->sum(fn (Booking $booking) => (float) ($booking->service_fee_amount ?? 0)), 2),
                    'net_amount' => round($group->sum(fn (Booking $booking) => $this->calculateNetAmount($booking)), 2),
                    'unique_tembezi' => $group->pluck('bookable_id')->filter()->unique()->count(),
                    'last_booking_at' => $lastDate?->toIso8601String(),
                ];
            })
            ->sortByDesc('total_amount')
            ->values()
            ->all();
    }

    private function groupByTembezi(Collection $bookings): array
    {
        return $bookings
            ->groupBy(function (Booking $booking) {
                $bookableId = $booking->bookable?->id;

                return $bookableId ? (string) $bookableId : 'unassigned';
            })
            ->map(function (Collection $group) {
                $sample = $group->first();
                $bookable = $sample?->bookable;
                $organizer = $bookable?->organizer;
                $profile = $organizer?->tembeaOperatorProfile;
                $paid = $group->filter(fn (Booking $booking) => $booking->payment_status === 'paid');

                $lastDate = $group
                    ->map(fn (Booking $booking) => $this->resolveBookingDate($booking))
                    ->reduce(function (?Carbon $carry, Carbon $date) {
                        if ($carry === null || $date->greaterThan($carry)) {
                            return $date;
                        }

                        return $carry;
                    });

                return [
                    'bookable_id' => $bookable?->id,
                    'title' => $bookable?->title,
                    'type' => $bookable?->type,
                    'operator' => $organizer ? [
                        'id' => $organizer->id,
                        'company_name' => $profile?->company_name ?? $organizer->name,
                        'contact_name' => $profile?->contact_name ?? $organizer->name,
                        'contact_email' => $profile?->contact_email ?? $organizer->email,
                        'contact_phone' => $profile?->contact_phone ?? $organizer->phone,
                    ] : null,
                    'total_bookings' => $group->count(),
                    'paid_bookings' => $paid->count(),
                    'total_amount' => round($group->sum(fn (Booking $booking) => (float) ($booking->total_amount ?? 0)), 2),
                    'service_fee_amount' => round($group->sum(fn (Booking $booking) => (float) ($booking->service_fee_amount ?? 0)), 2),
                    'net_amount' => round($group->sum(fn (Booking $booking) => $this->calculateNetAmount($booking)), 2),
                    'last_booking_at' => $lastDate?->toIso8601String(),
                ];
            })
            ->sortByDesc('total_amount')
            ->values()
            ->all();
    }

    private function buildFinancialSummary(Collection $bookings, string $interval): array
    {
        $paidBookings = $bookings->filter(fn (Booking $booking) => $booking->payment_status === 'paid');
        $completedPayments = $paidBookings
            ->flatMap(fn (Booking $booking) => $booking->payments)
            ->filter(fn (Payment $payment) => $payment->status === 'completed');

        $totals = [
            'payments' => round($paidBookings->sum(fn (Booking $booking) => (float) ($booking->total_amount ?? 0)), 2),
            'commission' => round($paidBookings->sum(fn (Booking $booking) => (float) ($booking->service_fee_amount ?? 0)), 2),
            'mobile_money_charges' => round($completedPayments->sum(fn (Payment $payment) => (float) ($payment->fee_amount ?? 0)), 2),
            'operator_payouts' => round($paidBookings->sum(fn (Booking $booking) => $this->calculateNetAmount($booking)), 2),
        ];

        $buckets = $paidBookings
            ->groupBy(fn (Booking $booking) => $this->formatIntervalKey($booking, $interval))
            ->map(function (Collection $group, string $key) use ($interval) {
                $anchor = $this->determineAnchorDate($group);
                $bounds = $this->resolveIntervalBounds($anchor, $interval);
                $payments = $group
                    ->flatMap(fn (Booking $booking) => $booking->payments)
                    ->filter(fn (Payment $payment) => $payment->status === 'completed');

                return [
                    'key' => $key,
                    'label' => $this->formatIntervalLabel($bounds['start'], $bounds['end'], $interval),
                    'start' => $bounds['start']->toIso8601String(),
                    'end' => $bounds['end']->toIso8601String(),
                    'payments' => round($group->sum(fn (Booking $booking) => (float) ($booking->total_amount ?? 0)), 2),
                    'commission' => round($group->sum(fn (Booking $booking) => (float) ($booking->service_fee_amount ?? 0)), 2),
                    'mobile_money_charges' => round($payments->sum(fn (Payment $payment) => (float) ($payment->fee_amount ?? 0)), 2),
                    'operator_payouts' => round($group->sum(fn (Booking $booking) => $this->calculateNetAmount($booking)), 2),
                    'booking_count' => $group->count(),
                ];
            })
            ->sortBy('start')
            ->values()
            ->all();

        return [
            'interval' => $interval,
            'totals' => $totals,
            'buckets' => $buckets,
        ];
    }

    private function buildChargesSummary(): array
    {
        $rates = collect($this->mpesaCostService->getRates())
            ->map(function (array $rate) {
                return [
                    'min' => (float) ($rate['min'] ?? 0),
                    'max' => (float) ($rate['max'] ?? 0),
                    'transfer_to_mpesa' => $this->nullableFloat($rate['transfer_to_mpesa'] ?? null),
                    'transfer_to_other' => $this->nullableFloat($rate['transfer_to_other'] ?? null),
                    'withdraw_from_mpesa' => $this->nullableFloat($rate['withdraw_from_mpesa'] ?? null),
                    'receiving_to_till_min' => $this->nullableFloat($rate['receiving_to_till_min'] ?? null),
                    'receiving_to_till_max' => $this->nullableFloat($rate['receiving_to_till_max'] ?? null),
                ];
            })
            ->values()
            ->all();

        $commissionRate = (float) config('services.bookings.tembea_commission_rate', 0.025);

        $commissionGroups = array_map(function (array $rate) use ($commissionRate) {
            $min = (float) ($rate['min'] ?? 0);
            $max = (float) ($rate['max'] ?? 0);

            return [
                'min' => $min,
                'max' => $max,
                'commission_min' => round($min * $commissionRate, 2),
                'commission_max' => round($max * $commissionRate, 2),
            ];
        }, $rates);

        return [
            'mpesa' => $rates,
            'commission' => [
                'rate' => $commissionRate,
                'percentage' => round($commissionRate * 100, 3),
                'groups' => $commissionGroups,
            ],
        ];
    }

    private function calculateNetAmount(Booking $booking): float
    {
        if ($booking->net_amount !== null) {
            return (float) $booking->net_amount;
        }

        $total = (float) ($booking->total_amount ?? 0);
        $serviceFee = (float) ($booking->service_fee_amount ?? 0);

        return round($total - $serviceFee, 2);
    }

    private function resolveBookingDate(Booking $booking): Carbon
    {
        $date = $booking->paid_at ?? $booking->updated_at ?? $booking->created_at ?? Carbon::now();

        if ($date instanceof Carbon) {
            return $date->copy();
        }

        return Carbon::parse((string) $date);
    }

    private function determineAnchorDate(Collection $bookings): Carbon
    {
        $dates = $bookings
            ->map(fn (Booking $booking) => $this->resolveBookingDate($booking));

        $first = $dates->sort(function (Carbon $a, Carbon $b) {
            if ($a->equalTo($b)) {
                return 0;
            }

            return $a->lessThan($b) ? -1 : 1;
        })->first();

        return $first ? $first->copy() : Carbon::now();
    }

    private function resolveIntervalBounds(Carbon $anchor, string $interval): array
    {
        return match ($interval) {
            'day' => [
                'start' => $anchor->copy()->startOfDay(),
                'end' => $anchor->copy()->endOfDay(),
            ],
            'week' => [
                'start' => $anchor->copy()->startOfWeek(),
                'end' => $anchor->copy()->endOfWeek(),
            ],
            'year' => [
                'start' => $anchor->copy()->startOfYear(),
                'end' => $anchor->copy()->endOfYear(),
            ],
            default => [
                'start' => $anchor->copy()->startOfMonth(),
                'end' => $anchor->copy()->endOfMonth(),
            ],
        };
    }

    private function formatIntervalKey(Booking $booking, string $interval): string
    {
        $date = $this->resolveBookingDate($booking);

        return match ($interval) {
            'day' => $date->format('Y-m-d'),
            'week' => sprintf('%d-W%02d', $date->isoWeekYear(), $date->isoWeek()),
            'year' => $date->format('Y'),
            default => $date->format('Y-m'),
        };
    }

    private function formatIntervalLabel(Carbon $start, Carbon $end, string $interval): string
    {
        return match ($interval) {
            'day' => $start->format('M j, Y'),
            'week' => 'Week of '.$start->format('M j, Y'),
            'year' => $start->format('Y'),
            default => $start->format('F Y'),
        };
    }

    private function nullableFloat(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }

        return (float) $value;
    }
}
