<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;

class LoginController extends Controller
{
    /**
     * Redirect the user to the Google authentication page.
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function redirectToGoogle(Request $request)
    {
        try {
            foreach (['client_id', 'client_secret', 'redirect'] as $key) {
                if (!config('services.google.'.$key)) {
                    throw new \LogicException('Google OAuth configuration is incomplete.');
                }
            }
            return Socialite::driver('google')->redirect();
        } catch (\Exception $exception) {
            return $this->authenticationFailure($request, $exception, 'redirect');
        }
    }

    /**
     * Obtain the user information from Google.
     *
     * @return \Illuminate\Contracts\Foundation\Application|\Illuminate\Foundation\Application|\Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector
     */
    public function handleGoogleCallback(Request $request)

    {
        if ($request->has('error')) {
            return redirect('/login')->with('error', 'Login Google dibatalkan atau tidak diizinkan. Silakan coba lagi.');
        }
        try {
            $googleUser = Socialite::driver('google')->user();
            if (!$googleUser->getId() || !filter_var($googleUser->getEmail(), FILTER_VALIDATE_EMAIL)) {
                throw new \UnexpectedValueException('Google identity is incomplete.');
            }
        } catch (\Exception $e) {
            return $this->authenticationFailure($request, $e, 'callback');
        }

        $user = User::where('google_id', $googleUser->id)->orWhere('email', $googleUser->email)->first();

        if ($user) {
            // Update google_id if not set (e.g. registered via email previously)
            if (is_null($user->google_id)) {
                $user->update([
                    'google_id' => $googleUser->id,
                    'password' => bcrypt(str()->random(16)),
                ]);
            }

            Auth::login($user);
            $request->session()->regenerate();
            if ($user->role === 'admin') {
                return redirect()->route('admin.dashboard');
            }
            // Check if profile is complete
            if (empty($user->no_hp) || empty($user->kabupaten_id) || $user->status !== 'active') {

                return redirect()->route('complete-profile');
            }

            return redirect()->intended('/dashboard');

        } else {
            // New User
            $newUser = User::create([
                'name' => $googleUser->name,
                'email' => $googleUser->email,
                'google_id' => $googleUser->id,
                'password' => bcrypt(str()->random(16)), // Dummy password
                'status' => 'pending',
                'role' => 'user',
                'team' => null, // To be defined
            ]);

            Auth::login($newUser);
            $request->session()->regenerate();

            return redirect()->route('complete-profile');
        }
    }

    private function authenticationFailure(Request $request, \Exception $exception, string $stage)
    {
        $reference = (string) Str::uuid();
        $invalidState = $exception instanceof InvalidStateException;
        $upstreamStatus = null;
        $providerError = null;
        if ($exception instanceof \GuzzleHttp\Exception\RequestException && $exception->hasResponse()) {
            $response = $exception->getResponse();
            $upstreamStatus = $response->getStatusCode();
            $error = json_decode((string) $response->getBody(), true)['error'] ?? null;
            if (in_array($error, ['invalid_grant', 'invalid_client', 'redirect_uri_mismatch', 'access_denied', 'temporarily_unavailable', 'server_error'], true)) {
                $providerError = $error;
            }
        }
        // Do not log exception messages, OAuth codes, tokens, session IDs or query strings.
        Log::warning('Google authentication failed', [
            'reference' => $reference,
            'stage' => $stage,
            'reason' => $invalidState ? 'invalid_state' : get_class($exception),
            'upstream_status' => $upstreamStatus,
            'provider_error' => $providerError,
            'request_host' => $request->getHost(),
            'callback_host' => parse_url(config('services.google.redirect', ''), PHP_URL_HOST),
            'session_driver' => config('session.driver'),
            'session_cookie_present' => $request->cookies->has(config('session.cookie')),
        ]);

        $message = $invalidState
            ? 'Sesi login Google tidak cocok atau sudah berakhir. Tutup tab login lain, lalu mulai lagi dari halaman login SISOKA.'
            : 'Login Google gagal diproses. Silakan coba lagi atau hubungi administrator.';

        return redirect('/login')->with('error', $message.' Referensi: '.$reference);
    }
}
