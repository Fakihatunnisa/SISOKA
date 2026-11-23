<?php

namespace Tests\Feature;

use App\Models\Kabupaten;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as GoogleUser;
use Mockery;
use Tests\TestCase;

class GoogleAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.google' => [
            'client_id' => 'test-client', 'client_secret' => 'test-secret',
            'redirect' => 'https://sisoka.example/auth/google/callback',
        ]]);
    }

    public function test_start_redirect_keeps_oauth_state_and_configured_callback(): void
    {
        $response = $this->get(route('auth.google'));
        $response->assertRedirect();
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertSame('https://sisoka.example/auth/google/callback', $query['redirect_uri']);
        $this->assertNotEmpty(session('state'));
        $this->assertSame(session('state'), $query['state']);
    }

    public function test_missing_session_state_is_rejected_and_logged_without_credentials(): void
    {
        Log::shouldReceive('warning')->once()->with('Google authentication failed', Mockery::on(function ($context) {
            return $context['reason'] === 'invalid_state'
                && $context['stage'] === 'callback'
                && ! str_contains(json_encode($context), 'private-code')
                && ! str_contains(json_encode($context), 'test-secret');
        }));
        $this->get('/auth/google/callback?code=private-code&state=missing-session')
            ->assertRedirect('/login')->assertSessionHas('error', fn ($message) => str_contains($message, 'Sesi login Google') && str_contains($message, 'Referensi:'));
        $this->assertGuest();
    }

    public function test_provider_failure_returns_a_reference_without_exposing_exception_text(): void
    {
        $provider = Mockery::mock();
        $provider->shouldReceive('user')->once()->andThrow(new \RuntimeException('token=private-token'));
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
        Log::shouldReceive('warning')->once()->with('Google authentication failed', Mockery::on(fn ($context) => ! str_contains(json_encode($context), 'private-token')));
        $this->get('/auth/google/callback?code=private-code')
            ->assertRedirect('/login')->assertSessionHas('error', fn ($message) => str_contains($message, 'Referensi:') && ! str_contains($message, 'private-token'));
        $this->assertGuest();
    }

    public function test_cancelled_login_does_not_attempt_token_exchange(): void
    {
        Socialite::shouldReceive('driver')->never();
        $this->get('/auth/google/callback?error=access_denied')->assertRedirect('/login')
            ->assertSessionHas('error', fn ($message) => str_contains($message, 'dibatalkan'));
        $this->assertGuest();
    }

    public function test_oauth_rejection_is_classified_without_logging_the_response_body(): void
    {
        $exception = new \GuzzleHttp\Exception\ClientException(
            'private-token',
            new \GuzzleHttp\Psr7\Request('POST', 'https://oauth2.googleapis.com/token'),
            new \GuzzleHttp\Psr7\Response(400, [], json_encode(['error' => 'invalid_grant', 'error_description' => 'private-token']))
        );
        $provider = Mockery::mock();
        $provider->shouldReceive('user')->once()->andThrow($exception);
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
        Log::shouldReceive('warning')->once()->with('Google authentication failed', Mockery::on(fn ($context) => $context['upstream_status'] === 400 && $context['provider_error'] === 'invalid_grant'
            && ! str_contains(json_encode($context), 'private-token')
        ));
        $this->get('/auth/google/callback?code=test')->assertRedirect('/login')->assertSessionHas('error');
        $this->assertGuest();
    }

    public function test_existing_email_can_log_in_and_link_google_identity(): void
    {
        $kabupaten = Kabupaten::create(['kode_kab' => '6101', 'nama_kabupaten' => 'Test']);
        $user = User::factory()->create([
            'email' => 'person@example.com', 'status' => 'active', 'role' => 'user',
            'no_hp' => '6281234567890', 'kabupaten_id' => $kabupaten->id,
        ]);
        $googleUser = (new GoogleUser)->map(['id' => 'google-123', 'email' => $user->email, 'name' => $user->name]);
        $provider = Mockery::mock();
        $provider->shouldReceive('user')->once()->andReturn($googleUser);
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
        $this->get('/auth/google/callback?code=test')->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
        $this->assertSame('google-123', $user->fresh()->google_id);
        $this->assertNotNull($user->fresh()->last_login_at);
    }

    public function test_incomplete_google_configuration_has_a_controlled_failure(): void
    {
        config(['services.google.client_secret' => '']);
        Socialite::shouldReceive('driver')->never();
        Log::shouldReceive('warning')->once();
        $this->get(route('auth.google'))->assertRedirect('/login')->assertSessionHas('error');
    }
}
