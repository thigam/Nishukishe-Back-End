<?php

namespace App\Http\Controllers;

use App\Models\SaccoRegistration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SaccoRegistrationController extends Controller
{
    /**
     * Store a new sacco registration request.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'sacco_name' => 'required|string|max:255',
            'registration_number' => 'nullable|string|max:255',
            'website_link' => 'nullable|url|max:255',
            'social_media_link' => 'nullable|string|max:255',
            'official_contacts' => 'nullable|array',
            'routes' => 'nullable|array',
            'contact_person_name' => 'required|string|max:255',
            'contact_person_email' => 'required|email|max:255',
            'contact_person_phone' => 'required|string|max:255',
        ]);

        $registration = SaccoRegistration::create([
            'sacco_name' => $validated['sacco_name'],
            'registration_number' => $validated['registration_number'] ?? null,
            'website_link' => $validated['website_link'] ?? null,
            'social_media_link' => $validated['social_media_link'] ?? null,
            'official_contacts' => $validated['official_contacts'] ?? [],
            'routes' => $validated['routes'] ?? [],
            'contact_person_name' => $validated['contact_person_name'],
            'contact_person_email' => $validated['contact_person_email'],
            'contact_person_phone' => $validated['contact_person_phone'],
            'status' => 'pending',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Sacco registration received successfully.',
            'registration' => $registration,
        ], 201);
    }

    /**
     * Get a list of sacco registrations for service persons / admin.
     */
    public function index(Request $request): JsonResponse
    {
        $status = $request->query('status', 'pending');

        $query = SaccoRegistration::orderBy('created_at', 'desc');

        if ($status && $status !== 'all') {
            $query->where('status', $status);
        }

        $registrations = $query->paginate(20);

        return response()->json([
            'success' => true,
            'registrations' => $registrations,
        ]);
    }

    /**
     * Update the status of a registration (approve/reject).
     */
    public function updateStatus(Request $request, $id): JsonResponse
    {
        $validated = $request->validate([
            'status' => 'required|in:pending,approved,rejected',
        ]);

        $registration = SaccoRegistration::findOrFail($id);
        $registration->update(['status' => $validated['status']]);

        return response()->json([
            'success' => true,
            'message' => 'Registration status updated.',
            'registration' => $registration,
        ]);
    }
}
