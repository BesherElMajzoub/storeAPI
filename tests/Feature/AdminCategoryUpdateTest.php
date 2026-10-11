<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminCategoryUpdateTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::firstOrCreate(['name' => 'Admin']));

        return $admin;
    }

    public function test_multipart_update_with_stringly_typed_form_values_succeeds(): void
    {
        $category = Category::create(['name' => 'Women', 'slug' => 'women', 'is_active' => true]);

        // Exactly what the admin form posts: strings for booleans, "" for empty fields.
        $this->actingAs($this->admin(), 'sanctum')
            ->post("/api/v1/admin/categories/{$category->id}", [
                '_method' => 'PATCH',
                'name' => 'Women Wear',
                'slug' => 'women',
                'parent_id' => '',
                'is_active' => 'false',
                'meta_description' => '',
            ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Women Wear');

        $category->refresh();
        $this->assertSame('Women Wear', $category->name);
        $this->assertFalse((bool) $category->is_active);
        $this->assertNull($category->parent_id);
        $this->assertNull($category->meta_description);
    }

    public function test_name_only_and_active_flag_only_updates_work(): void
    {
        $category = Category::create(['name' => 'Men', 'slug' => 'men', 'is_active' => true]);
        $admin = $this->admin();

        $this->actingAs($admin, 'sanctum')
            ->post("/api/v1/admin/categories/{$category->id}", ['_method' => 'PATCH', 'name' => 'Menswear'], ['Accept' => 'application/json'])
            ->assertOk();
        $this->actingAs($admin, 'sanctum')
            ->post("/api/v1/admin/categories/{$category->id}", ['_method' => 'PATCH', 'is_active' => 'true'], ['Accept' => 'application/json'])
            ->assertOk();

        $this->assertSame('Menswear', $category->fresh()->name);
    }

    public function test_update_with_a_new_image_and_unknown_fields_succeeds(): void
    {
        Storage::fake('public');
        $category = Category::create(['name' => 'Kids', 'slug' => 'kids', 'is_active' => true]);

        $this->actingAs($this->admin(), 'sanctum')
            ->post("/api/v1/admin/categories/{$category->id}", [
                '_method' => 'PATCH',
                'name' => 'Kids Club',
                'surprise_field' => 'ignored',
                'image' => UploadedFile::fake()->image('cover.jpg', 600, 800),
            ], ['Accept' => 'application/json'])
            ->assertOk();

        $this->assertCount(1, $category->fresh()->getMedia('category_image'));
    }

    public function test_admin_category_index_returns_nested_active_and_inactive_categories(): void
    {
        $root = Category::create(['name' => 'Women', 'slug' => 'women', 'is_active' => true, 'sort_order' => 1]);
        $active = Category::create(['name' => 'Dresses', 'slug' => 'dresses', 'parent_id' => $root->id, 'is_active' => true, 'sort_order' => 1]);
        Category::create(['name' => 'Archived', 'slug' => 'archived', 'parent_id' => $root->id, 'is_active' => false, 'sort_order' => 2]);
        Category::create(['name' => 'Evening', 'slug' => 'evening', 'parent_id' => $active->id, 'is_active' => true, 'sort_order' => 1]);

        $response = $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/admin/categories?depth=2')
            ->assertOk()
            ->assertJsonPath('data.0.id', $root->id)
            ->assertJsonPath('data.0.children.0.id', $active->id)
            ->assertJsonPath('data.0.children.1.is_active', false)
            ->assertJsonPath('data.0.children.0.children.0.name', 'Evening');

        $this->assertCount(2, $response->json('data.0.children'));
    }

    public function test_admin_category_depth_limits_the_nested_response(): void
    {
        $root = Category::create(['name' => 'Root', 'slug' => 'root', 'is_active' => true]);
        $child = Category::create(['name' => 'Child', 'slug' => 'child', 'parent_id' => $root->id, 'is_active' => true]);
        Category::create(['name' => 'Grandchild', 'slug' => 'grandchild', 'parent_id' => $child->id, 'is_active' => true]);

        $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/admin/categories?depth=1')
            ->assertOk()
            ->assertJsonPath('data.0.children.0.id', $child->id)
            ->assertJsonMissingPath('data.0.children.0.children');
    }
}
