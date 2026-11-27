<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Product;
use App\Models\Reservation;
use App\Models\Service;
use App\Models\Spa;
use App\Models\Therapist;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProductTest extends TestCase
{
    use RefreshDatabase;

    private Spa $spa1;
    private Spa $spa2;
    private User $owner1;
    private User $admin1;
    private User $staff1;
    private User $owner2;
    private Client $client1;
    private Service $service1;
    private Therapist $therapist1;

    protected function setUp(): void
    {
        parent::setUp();

        $this->spa1 = Spa::create([
            'name'   => 'Ocean Breeze Spa',
            'slug'   => 'ocean-breeze',
            'status' => 'active',
        ]);

        $this->spa2 = Spa::create([
            'name'   => 'Desert Rose Spa',
            'slug'   => 'desert-rose',
            'status' => 'active',
        ]);

        $this->owner1 = User::create([
            'name'      => 'Owner One',
            'email'     => 'owner1@yospa.test',
            'password'  => Hash::make('password123'),
            'spa_id'    => $this->spa1->id,
            'role'      => 'owner',
            'is_active' => true,
        ]);

        $this->admin1 = User::create([
            'name'      => 'Admin One',
            'email'     => 'admin1@yospa.test',
            'password'  => Hash::make('password123'),
            'spa_id'    => $this->spa1->id,
            'role'      => 'admin',
            'is_active' => true,
        ]);

        $this->staff1 = User::create([
            'name'      => 'Staff One',
            'email'     => 'staff1@yospa.test',
            'password'  => Hash::make('password123'),
            'spa_id'    => $this->spa1->id,
            'role'      => 'staff',
            'is_active' => true,
        ]);

        $this->owner2 = User::create([
            'name'      => 'Owner Two',
            'email'     => 'owner2@yospa.test',
            'password'  => Hash::make('password123'),
            'spa_id'    => $this->spa2->id,
            'role'      => 'owner',
            'is_active' => true,
        ]);

        $this->client1 = Client::create([
            'spa_id'    => $this->spa1->id,
            'full_name' => 'Sara Connor',
            'phone'     => '+212600000001',
            'email'     => 'sara@example.com',
        ]);

        $this->service1 = Service::create([
            'spa_id'           => $this->spa1->id,
            'name'             => 'Signature Hammam',
            'duration_minutes' => 60,
            'price'            => 350.00,
            'is_active'        => true,
        ]);

        $this->therapist1 = Therapist::create([
            'spa_id'    => $this->spa1->id,
            'name'      => 'Amina Zahra',
            'color'     => '#3B82F6',
            'is_active' => true,
        ]);
    }

    public function test_owner_and_admin_can_crud_products_and_enforce_uniqueness(): void
    {
        Sanctum::actingAs($this->owner1);

        // 1. Create product as owner
        $res = $this->postJson('/api/products', [
            'name'                => 'Eucalyptus Black Soap',
            'sku'                 => 'SOAP-EUC-01',
            'unit'                => 'g',
            'price'               => 85.50,
            'stock_quantity'      => 50,
            'low_stock_threshold' => 10,
            'description'         => 'Traditional black soap infused with eucalyptus essential oil.',
            'is_active'           => true,
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('product.name', 'Eucalyptus Black Soap')
            ->assertJsonPath('product.sku', 'SOAP-EUC-01')
            ->assertJsonPath('product.price', '85.50')
            ->assertJsonPath('product.stock_quantity', 50)
            ->assertJsonPath('product.spa_id', $this->spa1->id);

        $productId = $res->json('product.id');

        // 2. Duplicate name per spa rejected
        $this->postJson('/api/products', [
            'name'  => 'eucalyptus black soap', // case-insensitive
            'price' => 90.00,
        ])->assertStatus(422)
          ->assertJsonValidationErrors(['name']);

        // 3. Duplicate SKU per spa rejected
        $this->postJson('/api/products', [
            'name'  => 'Different Name',
            'sku'   => 'soap-euc-01', // case-insensitive
            'price' => 90.00,
        ])->assertStatus(422)
          ->assertJsonValidationErrors(['sku']);

        // 4. Admin can create product with null SKU
        Sanctum::actingAs($this->admin1);
        $resAdmin = $this->postJson('/api/products', [
            'name'  => 'Argan Hair Mask',
            'price' => 120.00,
        ]);
        $resAdmin->assertStatus(201);
        $adminProductId = $resAdmin->json('product.id');

        // 5. Update product ignoring self uniqueness
        $updateRes = $this->putJson("/api/products/{$productId}", [
            'name'           => 'Eucalyptus Black Soap',
            'sku'            => 'SOAP-EUC-01',
            'stock_quantity' => 45,
            'price'          => 89.00,
        ]);
        $updateRes->assertStatus(200)
            ->assertJsonPath('product.stock_quantity', 45)
            ->assertJsonPath('product.price', '89.00');

        // 6. Delete unused product
        $delRes = $this->deleteJson("/api/products/{$adminProductId}");
        $delRes->assertStatus(200)
            ->assertJson(['message' => 'Product deleted successfully']);

        $this->assertDatabaseMissing('products', ['id' => $adminProductId]);
    }

    public function test_staff_can_view_products_but_forbidden_from_write_operations(): void
    {
        $product = Product::create([
            'spa_id'         => $this->spa1->id,
            'name'           => 'Rose Water Toner',
            'price'          => 60.00,
            'stock_quantity' => 20,
            'is_active'      => true,
        ]);

        Sanctum::actingAs($this->staff1);

        // Staff can list
        $this->getJson('/api/products')->assertStatus(200);

        // Staff can show
        $this->getJson("/api/products/{$product->id}")
            ->assertStatus(200)
            ->assertJsonPath('name', 'Rose Water Toner');

        // Staff forbidden from create
        $this->postJson('/api/products', [
            'name'  => 'Clay Mask',
            'price' => 45.00,
        ])->assertStatus(403);

        // Staff forbidden from update
        $this->putJson("/api/products/{$product->id}", [
            'name' => 'Updated Rose Water',
        ])->assertStatus(403);

        // Staff forbidden from delete
        $this->deleteJson("/api/products/{$product->id}")
            ->assertStatus(403);
    }

    public function test_product_listing_pagination_filters_and_search(): void
    {
        Sanctum::actingAs($this->owner1);

        Product::create([
            'spa_id'              => $this->spa1->id,
            'name'                => 'Almond Scrub',
            'sku'                 => 'SCRUB_ALM_100',
            'price'               => 100.00,
            'stock_quantity'      => 3,
            'low_stock_threshold' => 5, // low stock
            'is_active'           => true,
        ]);

        Product::create([
            'spa_id'              => $this->spa1->id,
            'name'                => 'Lavender Oil',
            'sku'                 => 'OIL%LAV',
            'price'               => 150.00,
            'stock_quantity'      => 25,
            'low_stock_threshold' => 5,
            'is_active'           => true,
        ]);

        Product::create([
            'spa_id'              => $this->spa1->id,
            'name'                => 'Old Discontinued Soap',
            'sku'                 => 'DISC-01',
            'price'               => 30.00,
            'stock_quantity'      => 0,
            'low_stock_threshold' => 2,
            'is_active'           => false, // inactive
        ]);

        // 1. Unpaginated returns flat array with legacy header, active only by default
        $resUnpaginated = $this->getJson('/api/products');
        $resUnpaginated->assertStatus(200)
            ->assertHeader('X-Legacy-Unpaginated', '1')
            ->assertJsonCount(2);

        // 2. all=1 includes inactive
        $resAll = $this->getJson('/api/products?all=1');
        $resAll->assertStatus(200)
            ->assertJsonCount(3);

        // 3. low_stock=1 filters stock_quantity <= low_stock_threshold
        $resLowStock = $this->getJson('/api/products?low_stock=1');
        $resLowStock->assertStatus(200)
            ->assertJsonCount(1)
            ->assertJsonPath('0.name', 'Almond Scrub');

        // 4. Paginated list
        $resPaginated = $this->getJson('/api/products?page=1&per_page=1');
        $resPaginated->assertStatus(200)
            ->assertJsonStructure(['data', 'current_page', 'total', 'per_page'])
            ->assertJsonPath('total', 2)
            ->assertJsonPath('per_page', 1);

        // 5. Escaped LIKE search on name and sku
        $resSearch = $this->getJson('/api/products?search=' . urlencode('OIL%LAV'));
        $resSearch->assertStatus(200)
            ->assertJsonCount(1)
            ->assertJsonPath('0.name', 'Lavender Oil');
    }

    public function test_cross_spa_product_isolation(): void
    {
        $product2 = Product::create([
            'spa_id'         => $this->spa2->id,
            'name'           => 'Spa 2 Exclusive Balm',
            'price'          => 200.00,
            'stock_quantity' => 10,
            'is_active'      => true,
        ]);

        Sanctum::actingAs($this->owner1);

        // Cannot view Spa 2 product
        $this->getJson("/api/products/{$product2->id}")->assertStatus(404);

        // Cannot update Spa 2 product
        $this->putJson("/api/products/{$product2->id}", [
            'name' => 'Hacked Balm',
        ])->assertStatus(404);

        // Cannot delete Spa 2 product
        $this->deleteJson("/api/products/{$product2->id}")->assertStatus(404);

        // Cannot attach Spa 2 product to Spa 1 reservation
        $res = $this->postJson('/api/reservations', [
            'client_id'   => $this->client1->id,
            'service_id'  => $this->service1->id,
            'start_time'  => '2026-12-01 10:00:00',
            'end_time'    => '2026-12-01 11:00:00',
            'total_price' => 350.00,
            'products'    => [
                ['product_id' => $product2->id, 'quantity' => 1],
            ],
        ]);

        $res->assertStatus(422)
            ->assertJsonValidationErrors(['products']);
    }

    public function test_attaching_products_on_reservation_store_snapshots_price_and_deducts_stock(): void
    {
        Sanctum::actingAs($this->owner1);

        $oil = Product::create([
            'spa_id'         => $this->spa1->id,
            'name'           => 'Argan Oil 50ml',
            'price'          => 120.00,
            'stock_quantity' => 20,
            'is_active'      => true,
        ]);

        $scrub = Product::create([
            'spa_id'         => $this->spa1->id,
            'name'           => 'Coffee Body Scrub',
            'price'          => 80.00,
            'stock_quantity' => 15,
            'is_active'      => true,
        ]);

        // Attach products, including duplicate item in array to test auto-merging
        $res = $this->postJson('/api/reservations', [
            'client_id'   => $this->client1->id,
            'service_id'  => $this->service1->id,
            'start_time'  => '2026-12-01 10:00:00',
            'end_time'    => '2026-12-01 11:00:00',
            'total_price' => 550.00,
            'products'    => [
                ['product_id' => $oil->id, 'quantity' => 2],
                ['product_id' => $oil->id, 'quantity' => 1], // Duplicate oil merged to qty 3
                ['product_id' => $scrub->id, 'quantity' => 2],
            ],
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('reservation.products.0.pivot.quantity', 3)
            ->assertJsonPath('reservation.products.0.pivot.unit_price', '120.00')
            ->assertJsonPath('reservation.products.1.pivot.quantity', 2)
            ->assertJsonPath('reservation.products.1.pivot.unit_price', '80.00');

        // Stock accurately decremented
        $this->assertEquals(17, $oil->fresh()->stock_quantity); // 20 - 3 = 17
        $this->assertEquals(13, $scrub->fresh()->stock_quantity); // 15 - 2 = 13
    }

    public function test_insufficient_stock_or_inactive_product_aborts_reservation_creation(): void
    {
        Sanctum::actingAs($this->owner1);

        $product = Product::create([
            'spa_id'         => $this->spa1->id,
            'name'           => 'Rare Saffron Serum',
            'price'          => 400.00,
            'stock_quantity' => 2,
            'is_active'      => true,
        ]);

        // 1. Requesting quantity > available stock (3 > 2)
        $res = $this->postJson('/api/reservations', [
            'client_id'   => $this->client1->id,
            'service_id'  => $this->service1->id,
            'start_time'  => '2026-12-01 10:00:00',
            'end_time'    => '2026-12-01 11:00:00',
            'total_price' => 750.00,
            'products'    => [
                ['product_id' => $product->id, 'quantity' => 3],
            ],
        ]);

        $res->assertStatus(422)
            ->assertJsonValidationErrors(['products'])
            ->assertJsonPath('errors.products.0', 'Insufficient stock for Rare Saffron Serum (available: 2).');

        // Stock is untouched
        $this->assertEquals(2, $product->fresh()->stock_quantity);
        $this->assertEquals(0, Reservation::count());

        // 2. Inactive product rejected
        $product->update(['is_active' => false]);
        $resInactive = $this->postJson('/api/reservations', [
            'client_id'   => $this->client1->id,
            'service_id'  => $this->service1->id,
            'start_time'  => '2026-12-01 10:00:00',
            'end_time'    => '2026-12-01 11:00:00',
            'total_price' => 750.00,
            'products'    => [
                ['product_id' => $product->id, 'quantity' => 1],
            ],
        ]);

        $resInactive->assertStatus(422)
            ->assertJsonValidationErrors(['products']);
    }

    public function test_updating_reservation_products_adjusts_stock_deltas_accurately(): void
    {
        Sanctum::actingAs($this->owner1);

        $oil = Product::create([
            'spa_id'         => $this->spa1->id,
            'name'           => 'Argan Oil',
            'price'          => 100.00,
            'stock_quantity' => 10,
            'is_active'      => true,
        ]);

        $mask = Product::create([
            'spa_id'         => $this->spa1->id,
            'name'           => 'Clay Mask',
            'price'          => 50.00,
            'stock_quantity' => 10,
            'is_active'      => true,
        ]);

        $res = $this->postJson('/api/reservations', [
            'client_id'   => $this->client1->id,
            'service_id'  => $this->service1->id,
            'start_time'  => '2026-12-01 10:00:00',
            'end_time'    => '2026-12-01 11:00:00',
            'total_price' => 450.00,
            'products'    => [
                ['product_id' => $oil->id, 'quantity' => 2],
            ],
        ]);
        $res->assertStatus(201);
        $reservationId = $res->json('reservation.id');

        $this->assertEquals(8, $oil->fresh()->stock_quantity); // 10 - 2 = 8

        // Change catalog price of oil
        $oil->update(['price' => 150.00]);

        // Update reservation: increase oil from 2 to 4, add mask qty 1
        $updateRes = $this->putJson("/api/reservations/{$reservationId}", [
            'products' => [
                ['product_id' => $oil->id, 'quantity' => 4],
                ['product_id' => $mask->id, 'quantity' => 1],
            ],
        ]);

        $updateRes->assertStatus(200);

        // Verify oil stock deducted by additional 2 (8 - 2 = 6), mask deducted by 1 (10 - 1 = 9)
        $this->assertEquals(6, $oil->fresh()->stock_quantity);
        $this->assertEquals(9, $mask->fresh()->stock_quantity);

        // Verify oil historical snapshot unit_price stays 100.00, while new mask snapshots 50.00
        $pivotOil = $updateRes->json('reservation.products.0');
        $this->assertEquals('100.00', $pivotOil['pivot']['unit_price']);

        // Remove oil completely from reservation
        $removeRes = $this->putJson("/api/reservations/{$reservationId}", [
            'products' => [
                ['product_id' => $mask->id, 'quantity' => 1],
            ],
        ]);
        $removeRes->assertStatus(200);

        // Oil stock restored (6 + 4 = 10)
        $this->assertEquals(10, $oil->fresh()->stock_quantity);
        $this->assertEquals(9, $mask->fresh()->stock_quantity);
    }

    public function test_cancelling_and_reactivating_reservation_manages_stock(): void
    {
        Sanctum::actingAs($this->owner1);

        $oil = Product::create([
            'spa_id'         => $this->spa1->id,
            'name'           => 'Argan Oil',
            'price'          => 100.00,
            'stock_quantity' => 10,
            'is_active'      => true,
        ]);

        $res = $this->postJson('/api/reservations', [
            'client_id'   => $this->client1->id,
            'service_id'  => $this->service1->id,
            'start_time'  => '2026-12-01 10:00:00',
            'end_time'    => '2026-12-01 11:00:00',
            'status'      => 'confirmed',
            'total_price' => 450.00,
            'products'    => [
                ['product_id' => $oil->id, 'quantity' => 3],
            ],
        ]);
        $reservationId = $res->json('reservation.id');
        $this->assertEquals(7, $oil->fresh()->stock_quantity);

        // Cancel reservation -> stock restored
        $cancelRes = $this->putJson("/api/reservations/{$reservationId}", [
            'status' => 'cancelled',
        ]);
        $cancelRes->assertStatus(200);
        $this->assertEquals(10, $oil->fresh()->stock_quantity);

        // Reactivate reservation -> stock re-deducted
        $reactivateRes = $this->putJson("/api/reservations/{$reservationId}", [
            'status' => 'confirmed',
        ]);
        $reactivateRes->assertStatus(200);
        $this->assertEquals(7, $oil->fresh()->stock_quantity);

        // Cancel again
        $this->putJson("/api/reservations/{$reservationId}", ['status' => 'cancelled'])->assertStatus(200);
        $this->assertEquals(10, $oil->fresh()->stock_quantity);

        // Artificially deplete stock to 1
        $oil->update(['stock_quantity' => 1]);

        // Reactivating fails due to insufficient stock (needs 3, has 1)
        $failReactivate = $this->putJson("/api/reservations/{$reservationId}", [
            'status' => 'confirmed',
        ]);
        $failReactivate->assertStatus(422)
            ->assertJsonValidationErrors(['products']);
    }

    public function test_deleting_reservation_restores_stock_and_attached_product_cannot_be_deleted(): void
    {
        Sanctum::actingAs($this->owner1);

        $oil = Product::create([
            'spa_id'         => $this->spa1->id,
            'name'           => 'Argan Oil',
            'price'          => 100.00,
            'stock_quantity' => 10,
            'is_active'      => true,
        ]);

        $res = $this->postJson('/api/reservations', [
            'client_id'   => $this->client1->id,
            'service_id'  => $this->service1->id,
            'start_time'  => '2026-12-01 10:00:00',
            'end_time'    => '2026-12-01 11:00:00',
            'total_price' => 450.00,
            'products'    => [
                ['product_id' => $oil->id, 'quantity' => 4],
            ],
        ]);
        $reservationId = $res->json('reservation.id');
        $this->assertEquals(6, $oil->fresh()->stock_quantity);

        // Attempt to delete product attached to reservation -> 409 Conflict
        $delProductRes = $this->deleteJson("/api/products/{$oil->id}");
        $delProductRes->assertStatus(409)
            ->assertJson(['message' => "This product is used in existing reservations and can't be deleted. Deactivate it instead."]);

        // Delete active reservation -> restores stock to 10
        $delRes = $this->deleteJson("/api/reservations/{$reservationId}");
        $delRes->assertStatus(200);
        $this->assertEquals(10, $oil->fresh()->stock_quantity);

        // Now product can be safely deleted
        $this->deleteJson("/api/products/{$oil->id}")->assertStatus(200);
        $this->assertDatabaseMissing('products', ['id' => $oil->id]);
    }
}
