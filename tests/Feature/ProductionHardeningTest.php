<?php

namespace Tests\Feature;

use App\Models\Spa;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class ProductionHardeningTest extends TestCase
{
    use RefreshDatabase;

    private Spa $spa;
    private User $owner;
    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->spa = Spa::create([
            'name' => 'Hardening Spa',
            'slug' => 'hardening-spa',
        ]);

        $this->owner = User::create([
            'spa_id'    => $this->spa->id,
            'name'      => 'Hardening Owner',
            'email'     => 'hardening-owner@yospa.test',
            'password'  => bcrypt('password123'),
            'role'      => 'owner',
            'is_active' => true,
        ]);

        $this->staff = User::create([
            'spa_id'    => $this->spa->id,
            'name'      => 'Hardening Staff',
            'email'     => 'hardening-staff@yospa.test',
            'password'  => bcrypt('password123'),
            'role'      => 'staff',
            'is_active' => true,
        ]);
    }

    public function test_login_throttle_per_email_and_ip(): void
    {
        RateLimiter::clear('hardening-target@yospa.test|198.51.100.1');

        $ip = '198.51.100.1';
        $email = 'hardening-target@yospa.test';

        // First 5 attempts fail with 422
        for ($i = 0; $i < 5; $i++) {
            $response = $this->withServerVariables(['REMOTE_ADDR' => $ip])
                ->postJson('/api/login', [
                    'email'    => $email,
                    'password' => 'wrong-password',
                ]);
            $response->assertStatus(422);
        }

        // 6th attempt should be blocked with 429
        $blocked = $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->postJson('/api/login', [
                'email'    => $email,
                'password' => 'wrong-password',
            ]);

        $blocked->assertStatus(429);
        $blocked->assertJson([
            'message' => 'Too many login attempts. Please try again later.',
        ]);
        $this->assertTrue($blocked->headers->has('Retry-After'));
    }

    public function test_login_throttle_per_ip(): void
    {
        $ip = '198.51.100.2';
        RateLimiter::clear($ip);

        // 20 attempts with different emails from the same IP
        for ($i = 1; $i <= 20; $i++) {
            $response = $this->withServerVariables(['REMOTE_ADDR' => $ip])
                ->postJson('/api/login', [
                    'email'    => "user{$i}@yospa.test",
                    'password' => 'wrong-password',
                ]);
            $response->assertStatus(422);
        }

        // 21st attempt from same IP blocked with 429
        $blocked = $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->postJson('/api/login', [
                'email'    => 'another-user@yospa.test',
                'password' => 'wrong-password',
            ]);

        $blocked->assertStatus(429);
        $blocked->assertJson([
            'message' => 'Too many login attempts. Please try again later.',
        ]);
        $this->assertTrue($blocked->headers->has('Retry-After'));
    }

    public function test_expired_sanctum_token_returns_401(): void
    {
        // 1. Issue fresh token and verify it succeeds
        $validToken = $this->owner->createToken('spa-token-valid')->plainTextToken;
        $validResponse = $this->withHeader('Authorization', 'Bearer ' . $validToken)
            ->getJson('/api/user');
        $validResponse->assertStatus(200);

        // 2. Issue separate token and expire it in DB (created 8 days ago, default expiration is 7 days / 10080 min)
        $expiredTokenRecord = $this->owner->createToken('spa-token-expired');
        DB::table('personal_access_tokens')
            ->where('id', $expiredTokenRecord->accessToken->id)
            ->update([
                'created_at' => now()->subMinutes(10081),
            ]);

        // Reset cached auth guard instance
        auth()->forgetGuards();

        $expiredResponse = $this->withHeader('Authorization', 'Bearer ' . $expiredTokenRecord->plainTextToken)
            ->getJson('/api/user');
        $expiredResponse->assertStatus(401);
        $expiredResponse->assertJson([
            'message' => 'Unauthenticated.',
        ]);
    }

    public function test_json_error_shapes_without_accept_header(): void
    {
        // 1. 401 Unauthenticated without Accept header
        $r401 = $this->call('GET', '/api/user');
        $r401->assertStatus(401);
        $this->assertStringContainsString('application/json', $r401->headers->get('Content-Type'));
        $r401->assertJson(['message' => 'Unauthenticated.']);

        // 2. 404 Route Not Found without Accept header
        $r404 = $this->call('GET', '/api/nonexistent-route-xyz');
        $r404->assertStatus(404);
        $this->assertStringContainsString('application/json', $r404->headers->get('Content-Type'));
        $this->assertNotEmpty($r404->json('message'));

        // 3. 403 Forbidden without Accept header
        $client = \App\Models\Client::create([
            'spa_id'    => $this->spa->id,
            'full_name' => 'Test Client',
            'phone'     => '1234567890',
        ]);
        $token = $this->staff->createToken('staff-token')->plainTextToken;
        $r403 = $this->call(
            'DELETE',
            '/api/clients/' . $client->id,
            [],
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]
        );
        $r403->assertStatus(403);
        $this->assertStringContainsString('application/json', $r403->headers->get('Content-Type'));
        $r403->assertJson(['message' => 'Unauthorized action.']);

        // 4. 422 Validation Error without Accept header
        $r422 = $this->call(
            'POST',
            '/api/login',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([])
        );
        $r422->assertStatus(422);
        $this->assertStringContainsString('application/json', $r422->headers->get('Content-Type'));
        $this->assertArrayHasKey('errors', $r422->json());

        // 5. 429 Throttle without Accept header
        $ip = '198.51.100.99';
        $email = 'throttle-test@yospa.test';
        RateLimiter::clear("{$email}|{$ip}");
        for ($i = 0; $i < 5; $i++) {
            $this->call(
                'POST',
                '/api/login',
                [],
                [],
                [],
                ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $ip],
                json_encode(['email' => $email, 'password' => 'wrong'])
            );
        }
        $r429 = $this->call(
            'POST',
            '/api/login',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $ip],
            json_encode(['email' => $email, 'password' => 'wrong'])
        );
        $r429->assertStatus(429);
        $this->assertStringContainsString('application/json', $r429->headers->get('Content-Type'));
        $r429->assertJson(['message' => 'Too many login attempts. Please try again later.']);
        $this->assertTrue($r429->headers->has('Retry-After'));
    }

    public function test_cors_preflight_allowed_vs_disallowed_origin(): void
    {
        // 1. Allowed origin (http://localhost:3000 is default when local or set in config)
        config(['cors.allowed_origins' => ['http://localhost:3000', 'https://app.yospa.test']]);

        $allowedResponse = $this->call(
            'OPTIONS',
            '/api/login',
            [],
            [],
            [],
            [
                'HTTP_ORIGIN' => 'https://app.yospa.test',
                'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
                'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'Content-Type, Authorization',
            ]
        );
        $this->assertEquals('https://app.yospa.test', $allowedResponse->headers->get('Access-Control-Allow-Origin'));

        // 2. Disallowed origin
        $disallowedResponse = $this->call(
            'OPTIONS',
            '/api/login',
            [],
            [],
            [],
            [
                'HTTP_ORIGIN' => 'https://malicious-site.example.com',
                'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            ]
        );
        $this->assertNotEquals(
            'https://malicious-site.example.com',
            $disallowedResponse->headers->get('Access-Control-Allow-Origin')
        );
    }
}
