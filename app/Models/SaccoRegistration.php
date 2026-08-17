<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SaccoRegistration extends Model
{
    use HasFactory;

    protected $fillable = [
        'sacco_name',
        'registration_number',
        'website_link',
        'social_media_link',
        'official_contacts',
        'routes',
        'contact_person_name',
        'contact_person_email',
        'contact_person_phone',
        'status',
    ];

    protected $casts = [
        'official_contacts' => 'array',
        'routes' => 'array',
    ];
}
