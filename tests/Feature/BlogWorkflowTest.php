<?php

namespace Tests\Feature;

use App\Models\BlogPost;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class BlogWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_service_person_can_create_submit_and_publish_blog(): void
    {
        $serviceUser = User::create([
            'name' => 'Service Author',
            'email' => 'author@example.com',
            'phone' => '254700000001',
            'password' => Hash::make('password'),
            'role' => UserRole::SERVICE_PERSON,
            'is_verified' => true,
            'is_approved' => true,
        ]);

        $superAdmin = User::create([
            'name' => 'Super Admin',
            'email' => 'admin@example.com',
            'phone' => '254700000002',
            'password' => Hash::make('password'),
            'role' => UserRole::SUPER_ADMIN,
            'is_verified' => true,
            'is_approved' => true,
        ]);

        $createResponse = $this->actingAs($serviceUser, 'sanctum')->postJson('/api/v1/blogs', [
            'title' => 'My first post',
            'content' => '<p>Hello world</p>',
            'excerpt' => 'Hello world excerpt',
        ])->assertCreated();

        $postId = $createResponse->json('data.id');
        $this->assertNotNull($postId);

        $this->actingAs($serviceUser, 'sanctum')->patchJson("/api/v1/blogs/{$postId}", [
            'title' => 'Updated title',
            'content' => '<p>Updated content</p>',
        ])->assertOk()->assertJsonPath('data.title', 'Updated title');

        $this->actingAs($serviceUser, 'sanctum')->postJson("/api/v1/blogs/{$postId}/submit")
            ->assertOk();

        $this->actingAs($superAdmin, 'sanctum')->postJson("/api/v1/blogs/{$postId}/approve")
            ->assertOk();

        $post = BlogPost::findOrFail($postId);
        $this->assertEquals(BlogPost::STATUS_PUBLISHED, $post->status);
        $this->assertNotNull($post->slug);

        $this->getJson('/api/v2/blogs/public')
            ->assertOk()
            ->assertJsonFragment(['slug' => $post->slug]);

        $this->getJson("/api/v2/blogs/public/{$post->slug}")
            ->assertOk()
            ->assertJsonPath('data.slug', $post->slug)
            ->assertJsonPath('data.status', BlogPost::STATUS_PUBLISHED);
    }

    public function test_service_person_can_delete_any_of_their_blogs(): void
    {
        $serviceUser = User::create([
            'name' => 'Service Author',
            'email' => 'author-delete@example.com',
            'phone' => '254700000010',
            'password' => Hash::make('password'),
            'role' => UserRole::SERVICE_PERSON,
            'is_verified' => true,
            'is_approved' => true,
        ]);

        $superAdmin = User::create([
            'name' => 'Super Admin',
            'email' => 'admin-delete@example.com',
            'phone' => '254700000011',
            'password' => Hash::make('password'),
            'role' => UserRole::SUPER_ADMIN,
            'is_verified' => true,
            'is_approved' => true,
        ]);

        $createResponse = $this->actingAs($serviceUser, 'sanctum')->postJson('/api/v1/blogs', [
            'title' => 'Blog to delete',
            'content' => '<p>Delete me</p>',
        ])->assertCreated();

        $postId = $createResponse->json('data.id');

        $this->actingAs($serviceUser, 'sanctum')->postJson("/api/v1/blogs/{$postId}/submit")
            ->assertOk();

        $this->actingAs($superAdmin, 'sanctum')->postJson("/api/v1/blogs/{$postId}/approve")
            ->assertOk();

        $this->actingAs($serviceUser, 'sanctum')->deleteJson("/api/v1/blogs/{$postId}")
            ->assertNoContent();

        $this->assertDatabaseMissing('blog_posts', ['id' => $postId]);
        $this->assertDatabaseMissing('blog_post_versions', ['blog_post_id' => $postId]);
        $this->assertDatabaseMissing('blog_post_status_events', ['blog_post_id' => $postId]);
    }

    public function test_service_person_cannot_delete_someone_elses_blog(): void
    {
        $author = User::create([
            'name' => 'Author One',
            'email' => 'author-one@example.com',
            'phone' => '254700000012',
            'password' => Hash::make('password'),
            'role' => UserRole::SERVICE_PERSON,
            'is_verified' => true,
            'is_approved' => true,
        ]);

        $otherServiceUser = User::create([
            'name' => 'Author Two',
            'email' => 'author-two@example.com',
            'phone' => '254700000013',
            'password' => Hash::make('password'),
            'role' => UserRole::SERVICE_PERSON,
            'is_verified' => true,
            'is_approved' => true,
        ]);

        $postId = $this->actingAs($author, 'sanctum')->postJson('/api/v1/blogs', [
            'title' => 'Blog owned by author one',
            'content' => '<p>Author one content</p>',
        ])->assertCreated()->json('data.id');

        $this->actingAs($otherServiceUser, 'sanctum')->deleteJson("/api/v1/blogs/{$postId}")
            ->assertForbidden();

        $this->assertDatabaseHas('blog_posts', ['id' => $postId]);
    }
}
