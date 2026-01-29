<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Settlement;
use App\Models\UserRole;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

use App\Services\MpesaService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use App\Support\TembeaPayoutFeeCalculator;
use Throwable;

class TembeaPayoutController extends Controller
{
    public function __construct(private readonly MpesaService $mpesa)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $status = $request->query('status');
        $statuses = $status ? explode(',', $status) : ['pending', 'requested'];

        $settlements = Settlement::query()
            ->with([
                'bookable.organizer.tembeaOperatorProfile',
                'bookable.primaryPayoutProfile',
                'payoutProfile',
            ])
            ->withCount('bookings')
            ->whereIn('status', $statuses)
            ->whereHas('bookable', function ($query) {
                $query->where('type', 'tour_event')
                    ->whereHas('organizer', function ($organizerQuery) {
                        $organizerQuery->where('role', UserRole::TEMBEA);
                    });
            })
            ->orderByDesc('period_end')
            ->get();

        $data = $settlements->map(function (Settlement $settlement) {
            return $this->transformSettlement($settlement);
        })->values();

        return response()->json([
            'data' => $data,
        ]);
    }

    public function build(Request $request): JsonResponse
    {
        $bookings = Booking::query()
            ->whereNull('settlement_id')
            ->where('payment_status', 'paid')
            ->whereNotNull('bookable_id')
            ->whereHas('bookable', function ($query) {
                $query->where('type', 'tour_event')
                    ->whereHas('organizer', function ($organizerQuery) {
                        $organizerQuery->where('role', UserRole::TEMBEA);
                    });
            })
            ->with([
                'bookable.organizer.tembeaOperatorProfile',
                'bookable.primaryPayoutProfile',
            ])
            ->orderBy('bookable_id')
            ->get();

        if ($bookings->isEmpty()) {
            return response()->json([
                'data' => [],
                'meta' => [
                    'created_count' => 0,
                    'booking_count' => 0,
                    'message' => 'No paid Tembea bookings without settlements were found.',
                ],
            ]);
        }

        $now = Carbon::now();
        $user = $request->user();
        $settlementIds = [];
        $processedBookings = 0;

        DB::transaction(function () use ($bookings, $now, $user, &$settlementIds, &$processedBookings): void {
            foreach ($bookings->groupBy('bookable_id') as $bookableId => $group) {
                /** @var \Illuminate\Support\Collection<int, Booking> $group */
                $bookable = $group->first()?->bookable;

                if (!$bookable) {
                    continue;
                }

                $totalAmount = $group->sum(fn(Booking $booking) => $booking->total_amount ?? 0);
                $feeAmount = $group->sum(fn(Booking $booking) => $booking->service_fee_amount ?? 0);
                $netAmount = $group->sum(function (Booking $booking) {
                    if ($booking->net_amount !== null) {
                        return $booking->net_amount;
                    }

                    $serviceFee = $booking->service_fee_amount ?? 0;

                    return ($booking->total_amount ?? 0) - $serviceFee;
                });

                $paidDates = $group->map(function (Booking $booking) {
                    return $booking->paid_at ?? $booking->updated_at ?? $booking->created_at;
                })->filter();

                $metadata = [
                    'generated_at' => $now->toIso8601String(),
                    'bookings_count' => $group->count(),
                ];

                if ($user) {
                    $metadata['generated_by'] = [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                    ];
                }

                $settlement = Settlement::create([
                    'bookable_id' => $bookableId,
                    'payout_profile_id' => $bookable->primaryPayoutProfile?->id,
                    'total_amount' => $totalAmount,
                    'fee_amount' => $feeAmount,
                    'net_amount' => $netAmount,
                    'status' => 'pending',
                    'period_start' => $paidDates->isNotEmpty() ? $paidDates->min() : null,
                    'period_end' => $paidDates->isNotEmpty() ? $paidDates->max() : null,
                    'metadata' => $metadata,
                ]);

                Booking::whereIn('id', $group->pluck('id'))->update([
                    'settlement_id' => $settlement->id,
                ]);

                $processedBookings += $group->count();
                $settlementIds[] = $settlement->id;
            }
        });

        $settlements = Settlement::query()
            ->whereIn('id', $settlementIds)
            ->with([
                'bookable.organizer.tembeaOperatorProfile',
                'bookable.primaryPayoutProfile',
                'payoutProfile',
            ])
            ->withCount('bookings')
            ->orderByDesc('period_end')
            ->get();

        $data = $settlements->map(function (Settlement $settlement) {
            return $this->transformSettlement($settlement);
        })->values();

        $createdCount = count($settlementIds);
        $message = $createdCount > 0
            ? "Created {$createdCount} settlement" . ($createdCount === 1 ? '' : 's') . " for {$processedBookings} booking" . ($processedBookings === 1 ? '' : 's') . '.'
            : 'No paid Tembea bookings without settlements were found.';

        return response()->json([
            'data' => $data,
            'meta' => [
                'created_count' => $createdCount,
                'booking_count' => $processedBookings,
                'message' => $message,
            ],
        ]);
    }

    public function initiate(Request $request, Settlement $settlement, \App\Services\PaystackService $paystackService): JsonResponse
    {
        $this->ensureSettlementIsTembea($settlement);

        $settlement->loadMissing(
            'payoutProfile',
            'bookable.primaryPayoutProfile',
            'bookable.organizer.tembeaOperatorProfile'
        );

        if (!in_array($settlement->status, ['pending', 'requested'], true)) {
            return response()->json([
                'message' => 'Settlement is not pending or requested',
            ], 422);
        }

        $validated = $request->validate([
            'note' => ['nullable', 'string', 'max:500'],
            'amount' => ['nullable', 'numeric', 'min:0.01'],
        ]);

        $payoutProfile = $settlement->payoutProfile ?? $settlement->bookable?->primaryPayoutProfile;

        if (!$payoutProfile || $payoutProfile->payout_type !== 'mpesa') {
            return response()->json([
                'message' => 'Settlement payout profile is not configured for M-PESA payments.',
            ], 422);
        }

        $phoneNumber = $payoutProfile->phone_number;

        if (!is_string($phoneNumber) || $phoneNumber === '') {
            return response()->json([
                'message' => 'Settlement payout profile is missing a recipient phone number.',
            ], 422);
        }

        $amount = $validated['amount'] ?? $settlement->requested_amount ?? $settlement->net_amount ?? 0;
        $amount = (float) $amount;

        if ($amount <= 0) {
            return response()->json([
                'message' => 'Settlement amount must be greater than zero to initiate payout.',
            ], 422);
        }

        if ($amount - ($settlement->net_amount ?? 0) > 0.0001) {
            return response()->json([
                'message' => 'Payout amount cannot exceed the available net amount.',
            ], 422);
        }

        $metadata = $settlement->metadata ?? [];
        if (!is_array($metadata)) {
            $metadata = (array) $metadata;
        }

        $now = Carbon::now();
        $metadata['initiated_at'] = $now->toIso8601String();
        $metadata['approved_amount'] = round($amount, 2);
        $metadata['transaction_fee'] = TembeaPayoutFeeCalculator::estimate($amount);
        $metadata['net_after_fee'] = round(max($amount - $metadata['transaction_fee'], 0), 2);

        $user = $request->user();
        if ($user) {
            $metadata['initiated_by'] = [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ];
        }

        if (!empty($validated['note'])) {
            $metadata['initiation_note'] = $validated['note'];
        }

        $remarks = 'Tembea settlement #' . $settlement->id;
        $occasion = 'Tembea Payout';

        $provider = env('PAYMENT_PROVIDER', 'jenga');

        if ($provider === 'paystack') {
            try {
                // 1. Create Recipient
                $recipientData = [
                    'type' => 'mobile_money',
                    'name' => $payoutProfile->account_name ?? 'Tembea Operator', // Fallback name
                    'account_number' => $phoneNumber,
                    'bank_code' => 'MPESA', // Assuming M-Pesa for now
                    'currency' => 'KES',
                ];

                // Normalize phone for Paystack if needed (e.g. 07xx to 254xx)
                // Assuming Paystack handles standard formats, but good to ensure.
                // PaymentGatewayManager::normalizePhoneNumber logic could be reused here if extracted.

                $recipient = $paystackService->createTransferRecipient($recipientData);
                $recipientCode = $recipient['recipient_code'];

                // 2. Initiate Transfer
                $transferData = [
                    'source' => 'balance',
                    'reason' => $remarks,
                    'amount' => round($amount * 100), // Paystack uses kobo/cents
                    'recipient' => $recipientCode,
                ];

                $response = $paystackService->initiateTransfer($transferData);

                $metadata['paystack_transfer'] = [
                    'recipient_code' => $recipientCode,
                    'transfer_code' => $response['transfer_code'] ?? null,
                    'reference' => $response['reference'] ?? null,
                    'amount' => round($amount, 2),
                    'response' => $response,
                ];

            } catch (Throwable $e) {
                Log::error('Failed to initiate Tembea settlement payout via Paystack', [
                    'settlement_id' => $settlement->id,
                    'error' => $e->getMessage(),
                ]);
                return response()->json([
                    'message' => $e->getMessage() ?: 'Failed to initiate payout via Paystack.',
                ], 502);
            }

        } else {
            // Default M-Pesa (Direct)
            $originatorConversationId = (string) Str::uuid();
            try {
                $response = $this->mpesa->b2cPayment([
                    'OriginatorConversationID' => $originatorConversationId,
                    'Amount' => round($amount, 2),
                    'PartyB' => $phoneNumber,
                    'Remarks' => $remarks,
                    'Occasion' => $occasion,
                ]);
            } catch (Throwable $e) {
                Log::error('Failed to initiate Tembea settlement payout', [
                    'settlement_id' => $settlement->id,
                    'error' => $e->getMessage(),
                ]);
                return response()->json([
                    'message' => $e->getMessage() ?: 'Failed to initiate payout via M-PESA. Please try again later.',
                ], 502);
            }

            $conversationId = $response['ConversationID'] ?? $originatorConversationId;

            $metadata['mpesa_b2c'] = [
                'originator_conversation_id' => $originatorConversationId,
                'conversation_id' => $conversationId,
                'phone_number' => $phoneNumber,
                'amount' => round($amount, 2),
                'remarks' => $remarks,
                'occasion' => $occasion,
                'response' => $response,
            ];
        }

        $settlement->status = 'initiated';
        $settlement->settled_at = $now;
        $settlement->metadata = $metadata;
        $settlement->save();

        $settlement->load([
            'bookable.organizer.tembeaOperatorProfile',
            'bookable.primaryPayoutProfile',
            'payoutProfile',
        ])->loadCount('bookings');

        return response()->json([
            'data' => $this->transformSettlement($settlement),
        ]);
    }

    public function finalize(Request $request, Settlement $settlement, \App\Services\PaystackService $paystackService): JsonResponse
    {
        $this->ensureSettlementIsTembea($settlement);

        $validated = $request->validate([
            'otp' => 'required|string',
            'transfer_code' => 'required|string',
        ]);

        try {
            $response = $paystackService->finalizeTransfer([
                'transfer_code' => $validated['transfer_code'],
                'otp' => $validated['otp'],
            ]);

            // Update metadata with final response
            $metadata = $settlement->metadata ?? [];
            if (!is_array($metadata))
                $metadata = (array) $metadata;

            $metadata['paystack_transfer_final'] = $response;
            $settlement->metadata = $metadata;
            $settlement->save();

            return response()->json([
                'status' => 'success',
                'data' => $response,
            ]);

        } catch (Throwable $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 502);
        }
    }

    protected function transformSettlement(Settlement $settlement): array
    {
        $bookable = $settlement->bookable;
        $organizer = $bookable?->organizer;
        $operatorProfile = $organizer?->tembeaOperatorProfile;
        $payoutProfile = $settlement->payoutProfile ?? $bookable?->primaryPayoutProfile;
        $metadata = $settlement->metadata ?? [];

        if (!is_array($metadata)) {
            $metadata = (array) $metadata;
        }

        return [
            'id' => $settlement->id,
            'status' => $settlement->status,
            'total_amount' => $settlement->total_amount,
            'fee_amount' => $settlement->fee_amount,
            'net_amount' => $settlement->net_amount,
            'requested_amount' => $settlement->requested_amount,
            'approved_amount' => $metadata['approved_amount'] ?? null,
            'transaction_fee' => $metadata['transaction_fee'] ?? null,
            'net_after_fee' => $metadata['net_after_fee'] ?? null,
            'requested_at' => optional($settlement->requested_at)->toIso8601String(),
            'requested_by' => $settlement->requested_by ?? null,
            'period_start' => optional($settlement->period_start)->toIso8601String(),
            'period_end' => optional($settlement->period_end)->toIso8601String(),
            'settled_at' => optional($settlement->settled_at)->toIso8601String(),
            'bookings_count' => $settlement->bookings_count ?? 0,
            'bookable' => $bookable ? [
                'id' => $bookable->id,
                'title' => $bookable->title,
                'type' => $bookable->type,
                'slug' => $bookable->slug,
            ] : null,
            'operator' => $operatorProfile ? [
                'id' => $operatorProfile->id,
                'company_name' => $operatorProfile->company_name,
                'contact_name' => $operatorProfile->contact_name,
                'contact_email' => $operatorProfile->contact_email,
                'contact_phone' => $operatorProfile->contact_phone,
                'status' => $operatorProfile->status,
            ] : null,
            'payout_profile' => $payoutProfile ? [
                'id' => $payoutProfile->id,
                'payout_type' => $payoutProfile->payout_type,
                'phone_number' => $payoutProfile->phone_number,
                'till_number' => $payoutProfile->till_number,
                'paybill_number' => $payoutProfile->paybill_number,
                'account_name' => $payoutProfile->account_name,
                'bank_name' => $payoutProfile->bank_name,
                'bank_branch' => $payoutProfile->bank_branch,
                'bank_account_number' => $payoutProfile->bank_account_number,
            ] : null,
            'metadata' => $metadata ?: new \stdClass(),
        ];
    }

    protected function ensureSettlementIsTembea(Settlement $settlement): void
    {
        $settlement->loadMissing(['bookable.organizer.tembeaOperatorProfile']);

        $bookable = $settlement->bookable;
        $organizer = $bookable?->organizer;

        if (!$bookable || $bookable->type !== 'tour_event' || $organizer?->role !== UserRole::TEMBEA) {
            abort(404, 'Settlement not found');
        }
    }
}
