<?php

namespace App\Http\Controllers;

use App\Models\EmailTemplate;
use Illuminate\Http\Request;

class EmailTemplateController extends Controller
{
    public function index()
    {
        return EmailTemplate::where('is_active', true)->latest()->get();
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string',
            'content_html' => 'required|string',
        ]);

        return EmailTemplate::create($validated);
    }

    public function show(EmailTemplate $emailTemplate)
    {
        return $emailTemplate;
    }

    public function update(Request $request, EmailTemplate $emailTemplate)
    {
        $validated = $request->validate([
            'name' => 'sometimes|string',
            'content_html' => 'sometimes|string',
            'is_active' => 'sometimes|boolean',
        ]);

        $emailTemplate->update($validated);
        return $emailTemplate;
    }

    public function destroy(EmailTemplate $emailTemplate)
    {
        $emailTemplate->delete();
        return response()->noContent();
    }
}