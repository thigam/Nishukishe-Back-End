<?php

namespace App\Http\Controllers;

use App\Models\Bookable;
use App\Models\Settlement;
use App\Models\TembeaOperatorProfile;
use App\Models\UserRole;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use App\Support\TembeaPayoutFeeCalculator;

class TembeaOperatorController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $profile = $this->resolveProfile();

        if ($profile instanceof JsonResponse) {
            return $profile;
        }

        return response()->json($this->formatProfile($profile));
    }

    public function settlements(): JsonResponse
    {
        $profile = $this->resolveProfile();

        if ($profile instanceof JsonResponse) {
            return $profile;
        }

        $settlements = Settlement::query()
            ->with(['bookable'])
            ->whereHas('bookable', function ($query) use ($profile): void {
                $query->where('organizer_id', $profile->user_id);
            })
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        return response()->json([
            'data' => $settlements->map(fn(Settlement $settlement) => $this->formatSettlement($settlement))->values(),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $profile = $this->resolveProfile();

        if ($profile instanceof JsonResponse) {
            return $profile;
        }

        $validated = $request->validate([
            'company_name' => ['sometimes', 'string', 'max:255'],
            'contact_name' => ['sometimes', 'string', 'max:255'],
            'contact_email' => ['sometimes', 'email', 'max:255'],
            'contact_phone' => ['sometimes', 'string', 'max:50'],
            'public_email' => ['nullable', 'email', 'max:255'],
            'public_phone' => ['nullable', 'string', 'max:50'],
            'about' => ['nullable', 'string'],
            'website' => ['nullable', 'string', 'max:255'],
            'headquarters' => ['nullable', 'string', 'max:255'],
            'mpesa_accounts' => ['sometimes', 'array', 'max:10'],
            'mpesa_accounts.*.label' => ['nullable', 'string', 'max:120'],
            'mpesa_accounts.*.number' => ['nullable', 'string', 'max:30'],
        ]);

        $updatable = [
            'company_name',
            'contact_name',
            'contact_email',
            'contact_phone',
            'public_email',
            'public_phone',
        ];

        foreach ($updatable as $field) {
            if (array_key_exists($field, $validated)) {
                $profile->{$field} = $validated[$field];
            }
        }

        $metadata = $profile->metadata ?? [];

        if (array_key_exists('about', $validated)) {
            $metadata['about'] = $this->nullableTrimmedString($validated['about']);
        }

        if (array_key_exists('website', $validated)) {
            $metadata['website'] = $this->nullableTrimmedString($validated['website']);
        }

        if (array_key_exists('headquarters', $validated)) {
            $metadata['headquarters'] = $this->nullableTrimmedString($validated['headquarters']);
        }

        $mpesaAccounts = collect(Arr::get($validated, 'mpesa_accounts', []))
            ->map(function ($account) {
                $label = $this->nullableTrimmedString($account['label'] ?? null);
                $number = $this->normalizeMpesaNumber($account['number'] ?? null);

                if ($number === null) {
                    return null;
                }

                return array_filter([
                    'label' => $label,
                    'number' => $number,
                ], static fn($value) => $value !== null && $value !== '');
            })
            ->filter()
            ->values();

        if (array_key_exists('mpesa_accounts', $validated)) {
            $metadata['mpesa_accounts'] = $mpesaAccounts
                ->map(function ($account) {
                    return [
                        'label' => $account['label'] ?? null,
                        'number' => $account['number'] ?? null,
                    ];
                })
                ->all();
        }

        $profile->metadata = $metadata;

        DB::transaction(function () use ($profile, $mpesaAccounts): void {
            $profile->save();

            if ($mpesaAccounts->isNotEmpty()) {
                $this->syncPayoutProfiles($profile, $mpesaAccounts);
            }
        });

        $profile->refresh();

        return response()->json($this->formatProfile($profile));
    }

    public function requestPayout(Request $request, Settlement $settlement): JsonResponse
    {
        $profile = $this->resolveProfile();

        if ($profile instanceof JsonResponse) {
            return $profile;
        }

        $settlement->loadMissing('bookable.organizer');

        $bookable = $settlement->bookable;
        $organizer = $bookable?->organizer;

        if (!$bookable || $bookable->type !== 'tour_event' || $organizer?->id !== $profile->user_id) {
            abort(404, 'Settlement not found');
        }

        if (!in_array($settlement->status, ['pending', 'requested'], true)) {
            return response()->json([
                'message' => 'Settlement is not eligible for payout request.',
            ], 422);
        }

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $amount = round((float) $validated['amount'], 2);
        $outstanding = max(0, (float) ($settlement->net_amount ?? 0));

        if ($outstanding <= 0) {
            return response()->json([
                'message' => 'Settlement has no outstanding balance to request.',
            ], 422);
        }

        if ($amount > $outstanding + 0.0001) {
            return response()->json([
                'message' => 'Requested amount exceeds outstanding balance.',
            ], 422);
        }

        $fee = TembeaPayoutFeeCalculator::estimate($amount);
        $metadata = $settlement->metadata ?? [];

        if (!is_array($metadata)) {
            $metadata = (array) $metadata;
        }

        if (array_key_exists('note', $validated) && $validated['note'] !== null) {
            $metadata['request_note'] = $validated['note'];
        }

        $metadata['requested_transaction_fee'] = $fee;
        $metadata['requested_amount_after_fees'] = round(max($amount - $fee, 0), 2);

        $user = Auth::user();
        $now = Carbon::now();

        $settlement->requested_amount = $amount;
        $settlement->requested_at = $now;
        $settlement->requested_by = $user ? [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
        ] : null;
        $settlement->metadata = $metadata;

        if ($settlement->status === 'pending') {
            $settlement->status = 'requested';
            // $settlement->settled_at = $now; // Not settled yet
        }

        $settlement->save();
        $settlement->refresh();

        return response()->json([
            'data' => $this->formatSettlement($settlement),
        ]);
    }

    /**
     * @return \Illuminate\Http\JsonResponse|\App\Models\TembeaOperatorProfile
     */
    protected function resolveProfile()
    {
        $user = Auth::user();

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        if ($user->role !== UserRole::TEMBEA) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $profile = $user->tembeaOperatorProfile;

        if (!$profile) {
            return response()->json(['message' => 'Tembea operator profile not found'], 404);
        }

        return $profile;
    }

    protected function nullableTrimmedString(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    protected function normalizeMpesaNumber(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $value);

        return $digits !== '' ? $digits : null;
    }

    protected function syncPayoutProfiles(TembeaOperatorProfile $profile, \Illuminate\Support\Collection $accounts): void
    {
        $primaryAccount = $accounts->first();

        if (!$primaryAccount || !isset($primaryAccount['number'])) {
            return;
        }

        $label = $primaryAccount['label'] ?? null;
        $phoneNumber = $primaryAccount['number'];

        Bookable::query()
            ->where('organizer_id', $profile->user_id)
            ->where('type', 'tour_event')
            ->chunkById(50, function ($bookables) use ($label, $phoneNumber): void {
                foreach ($bookables as $bookable) {
                    /** @var Bookable $bookable */
                    $payoutProfile = $bookable->primaryPayoutProfile;

                    $metadata = $payoutProfile?->metadata ?? [];

                    if ($label !== null) {
                        $metadata['tembea_account_label'] = $label;
                    }

                    if ($payoutProfile) {
                        $payoutProfile->fill([
                            'payout_type' => 'mpesa',
                            'phone_number' => $phoneNumber,
                            'metadata' => $metadata,
                        ]);
                        $payoutProfile->save();
                    } else {
                        $bookable->payoutProfiles()->create([
                            'payout_type' => 'mpesa',
                            'phone_number' => $phoneNumber,
                            'is_primary' => true,
                            'metadata' => $metadata,
                        ]);
                    }
                }
            });
    }

    protected function formatProfile(TembeaOperatorProfile $profile): array
    {
        $metadata = $profile->metadata ?? [];

        if (empty($metadata)) {
            $metadata = new \stdClass();
        }

        return [
            'id' => $profile->id,
            'company_name' => $profile->company_name,
            'slug' => $profile->slug,
            'contact_name' => $profile->contact_name,
            'contact_email' => $profile->contact_email,
            'contact_phone' => $profile->contact_phone,
            'public_email' => $profile->public_email,
            'public_phone' => $profile->public_phone,
            'status' => $profile->status,
            'metadata' => $metadata,
            'created_at' => $profile->created_at,
            'updated_at' => $profile->updated_at,
        ];
    }

    protected function formatSettlement(Settlement $settlement): array
    {
        $metadata = $settlement->metadata ?? [];

        if (!is_array($metadata)) {
            $metadata = (array) $metadata;
        }

        $transactionFee = $metadata['requested_transaction_fee'] ?? null;
        if (!is_numeric($transactionFee)) {
            $transactionFee = TembeaPayoutFeeCalculator::estimate((float) ($settlement->requested_amount ?? $settlement->net_amount ?? 0));
        }

        return [
            'id' => $settlement->id,
            'status' => $settlement->status,
            'total_amount' => $settlement->total_amount,
            'fee_amount' => $settlement->fee_amount,
            'net_amount' => $settlement->net_amount,
            'transaction_fee' => round((float) $transactionFee, 2),
            'bookable' => $settlement->bookable ? [
                'id' => $settlement->bookable->id,
                'title' => $settlement->bookable->title,
                'slug' => $settlement->bookable->slug,
            ] : null,
            'requested_amount' => $settlement->requested_amount,
            'requested_at' => optional($settlement->requested_at)->toIso8601String(),
            'requested_by' => $settlement->requested_by ?? null,
            'period_start' => optional($settlement->period_start)->toIso8601String(),
            'period_end' => optional($settlement->period_end)->toIso8601String(),
            'metadata' => $metadata,
        ];
    }
}
