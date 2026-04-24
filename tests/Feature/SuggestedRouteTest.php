<?php

namespace Tests\Feature;

use App\Models\SuggestedRoute;
use App\Models\User;
use App\Models\UserRole;
use App\Models\Stops;
use App\Models\Sacco;
use App\Mail\SuggestedRouteDoneEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SuggestedRouteTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_fetch_suggested_route_options()
    {
        // Use insert for stops/saccos because they don't have factories and have string keys
        \DB::table('stops')->insert([
            'stop_id' => 'STOP1',
            'stop_name' => 'Nairobi CBD',
            'stop_lat' => 1.28,
            'stop_long' => 36.82
        ]);

        \DB::table('saccos')->insert([
            'sacco_id' => 'SACCO1',
            'sacco_name' => 'Super Sacco',
            'vehicle_type' => 'Bus',
            'join_date' => now(),
        ]);

        $response = $this->getJson('/api/suggested-routes/options');

        $response->assertStatus(200);
        $response->assertJsonFragment(['stop_id' => 'STOP1', 'stop_name' => 'Nairobi CBD']);
        $response->assertJsonFragment(['sacco_id' => 'SACCO1', 'sacco_name' => 'Super Sacco']);
    }

    public function test_authenticated_user_can_submit_suggestion()
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/suggested-routes', [
            'start_stop_manual' => 'Westlands',
            'end_stop_manual' => 'Kilimani',
            'sacco_manual' => 'Double M',
            'details' => 'Missing the variation via Waiyaki Way',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('suggested_routes', [
            'user_id' => $user->id,
            'start_stop_manual' => 'Westlands',
            'details' => 'Missing the variation via Waiyaki Way'
        ]);
    }

    public function test_service_person_with_permission_can_list_suggestions()
    {
        $servicePerson = User::factory()->create(['role' => UserRole::SERVICE_PERSON]);
        $servicePerson->permissions()->create(['permission' => 'manage_route_suggestions']);

        SuggestedRoute::factory()->count(3)->create(['status' => 'pending']);

        Sanctum::actingAs($servicePerson);

        $response = $this->getJson('/api/admin/suggested-routes');

        $response->assertStatus(200);
        $response->assertJsonCount(3, 'suggestions');
        $response->assertJsonStructure(['suggestions', 'analytics']);
    }

    public function test_unauthorized_user_cannot_list_suggestions()
    {
        $user = User::factory()->create(['role' => UserRole::USER]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/admin/suggested-routes');

        $response->assertStatus(403);
    }

    public function test_service_person_can_mark_suggestions_as_done_and_emails_are_sent()
    {
        Mail::fake();

        $servicePerson = User::factory()->create(['role' => UserRole::SERVICE_PERSON]);
        $servicePerson->permissions()->create(['permission' => 'manage_route_suggestions']);

        $user = User::factory()->create(['email' => 'commuter@example.com']);

        // Create matching suggestions
        SuggestedRoute::factory()->create([
            'user_id' => $user->id,
            'start_stop_manual' => 'Rongai',
            'end_stop_manual' => 'CBD',
            'status' => 'pending'
        ]);

        SuggestedRoute::factory()->create([
            'user_id' => $user->id,
            'start_stop_manual' => 'Rongai',
            'end_stop_manual' => 'CBD',
            'status' => 'pending'
        ]);

        Sanctum::actingAs($servicePerson);

        $response = $this->postJson('/api/admin/suggested-routes/mark-done', [
            'start_stop_manual' => 'Rongai',
            'end_stop_manual' => 'CBD'
        ]);

        $response->assertStatus(200);
        $response->assertJson(['count' => 2]);

        $this->assertEquals(0, SuggestedRoute::where('status', 'pending')->count());
        $this->assertEquals(2, SuggestedRoute::where('status', 'done')->count());

        Mail::assertSent(SuggestedRouteDoneEmail::class, 2);
    }
}
