# Google authentication troubleshooting

The login page returns a reference ID for callback failures. Search the application log for that reference and `Google authentication failed`. Logs contain the stage, exception class or `invalid_state`, callback/request hosts, session driver, presence of the session cookie, upstream HTTP status, and a whitelisted OAuth error code when available. OAuth codes, tokens, secrets, session IDs, and raw exception messages must never be logged.

- `invalid_state`: the callback could not validate the state stored at login start. Check that login starts and finishes on the same canonical hostname, session storage is persistent and shared across app instances, `APP_KEY` is stable across deployments/instances, and cookie domain/path/secure/SameSite settings match the public URL. Start again from the login page after closing older login tabs. Keep Socialite state validation enabled.
- `LogicException` at the redirect stage: check Google client ID, client secret and callback configuration.
- HTTP/client exceptions at the callback stage: check server connectivity, CA certificates, client credentials, and the exact callback registered in Google Cloud. Investigate by reference ID; do not expose upstream responses to users.

Set `APP_URL` to the canonical public origin. Set `GOOGLE_REDIRECT_URI` to that origin followed by `/auth/google/callback`; if empty, the application derives it from `APP_URL`. Register the identical callback in Google Cloud, including scheme, host, port, path and trailing-slash behavior. After configuration changes, rebuild Laravel's configuration cache using the deployment's normal procedure. If a reverse proxy terminates HTTPS, configure trusted proxies for the actual infrastructure.

Local checks cannot identify an existing production ticket without its timestamp/reference and server logs. Mocked tests cover login start, lost state, cancellation, provider failure, missing configuration, and existing-email login. Live Google consent and production session/cookie behavior require verification on the deployed origin.

# OTP configuration

Phone verification sends through the existing Wanesia integration using `WA_API_TOKEN` and optional `WA_API_URL`. Missing credentials, connection failures, non-success HTTP responses, provider rejection or unrecognized responses fail closed and invalidate the OTP. The current adapter accepts JSON `status: true` or `status: "success"`; confirm the response contract with the configured gateway before deployment. Tests fake the gateway; no real WhatsApp messages are sent during tests.

# Decimal input

Leading-zero fractions such as `0,123` and `0.123` are decimals. Existing imports retain the convention that `13.172` and `13,172` mean `13172`. For a nonzero decimal with exactly three fractional digits, add a trailing zero (`1.2340`) to distinguish it from a thousands group; the form does this automatically when rendering saved values. Mixed grouped formats (`1.234,00` or `1,234.00`) and repeated groups (`1.234.567`) remain supported. Invalid text is rejected before any values are written.
