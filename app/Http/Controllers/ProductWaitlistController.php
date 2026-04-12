<?php

namespace App\Http\Controllers;

use App\Models\ProductWaitlist;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ProductWaitlistController extends Controller
{
    /**
     * Store a newly created waitlist entry in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'sacco_id' => 'required|exists:saccos,sacco_id',
            'product_slug' => 'required|string|max:255',
            'contact_name' => 'nullable|string|max:255',
            'contact_phone' => 'nullable|string|max:255',
            'contact_email' => 'nullable|email|max:255',
            'notes' => 'nullable|string',
        ]);

        $entry = ProductWaitlist::updateOrCreate(
            [
                'sacco_id' => $validated['sacco_id'],
                'user_id' => Auth::id(),
                'product_slug' => $validated['product_slug'],
            ],
            $validated
        );

        return response()->json([
            'message' => 'Your interest has been recorded. We will contact you soon!',
            'data' => $entry
        ], 201);
    }

    /**
     * Display a listing of waitlist entries for superadmin.
     */
    public function index()
    {
        // Only superadmin should be able to access this via route middleware
        return ProductWaitlist::with(['sacco', 'user'])
            ->orderBy('created_at', 'desc')
            ->get();
    }
}
