# Access and data integrity fixes

## Behavior changes

| Area | Corrected behavior |
| --- | --- |
| Profile completion | Active accounts cannot change their approved region through completion endpoints. Submitting a pending/rejected profile clears phone verification and requires approval again. |
| Desa Cantik access | Progress reads, writes and participant deletion require the participant's region or provincial access. |
| Phone verification | OTPs are sent privately through the configured WhatsApp gateway, never rendered in HTML or flash messages. Failed delivery invalidates the code; expired/failed deliveries can be retried. Verification and resend endpoints are rate limited. Admin approval requires a verified phone. |
| Macro numbers | Leading-zero fractions retain their decimal value; invalid text is rejected. Existing Indonesian/US thousands formats remain supported, and three-digit fractions are rendered with a trailing zero so resubmitting the form preserves the value. |
| RH revisions | Every submitted revision header must belong to the selected active year, checked before any values are written. |
| Macro locks | Nonactive periods, indicators and dimensions cannot be modified through direct requests. The complete payload is validated before a transactional write. |
| Desa Cantik registration | The selected village must belong to the selected district, and that district must belong to the user's effective region. |
| Poverty imports | An interior blank or invalid row rejects the complete import before replacing existing data, preventing percentile shifts. Surrounding blank lines remain accepted. |
| Fenomena publication | Unverified/rejected records are hidden from guests and unrelated users. Authors and provincial users retain access; verified records remain publicly readable. |

## Google login ticket

The old callback caught all provider exceptions and returned the same `Google authentication failed` message without logging the cause. The callback now returns a reference ID and logs safe diagnostic metadata, including invalid OAuth state and whitelisted upstream OAuth errors. Cancellation has a separate message. Empty callback configuration falls back to `APP_URL`, and successful login explicitly regenerates the session.

These changes make failures diagnosable and address the empty callback fallback; they do not establish the cause of a historical production ticket. Use the reference ID and server logs to distinguish session/cookie configuration from provider or connectivity failures. See [Google authentication troubleshooting](google-authentication.md) for configuration and deployment checks, including the WhatsApp response contract and decimal-input convention.

## Validation

- PHP 8.4.27 with the committed dependency lock: **105 tests, 398 assertions, all passing**.
- PHP syntax checks passed for 191 files; all Blade templates compiled successfully, and Laravel registered 178 application routes.
- 39 new regression cases cover authorization, hierarchy validation, private OTP delivery, failure/retry handling, decimal parsing, numeric round trips, inactive data locks, cross-year RH requests, invalid imports, publication visibility and mocked Google OAuth flows.
- Four stale test failures were reproduced on the original source before correcting their fixtures/assertions: menu POST/DELETE requests lacked the referrer expected by `back()`, a menu test expected JavaScript URL conversion in server-rendered HTML, and the default home test expected 200 instead of the login redirect. Legacy PHPUnit test annotations were migrated to attributes.
- Dependencies were installed in a temporary source copy because the local XAMPP PHP 8.2 runtime does not satisfy the locked Symfony/PHP dependencies. No lockfile updates, production database changes, real Google sign-ins or real WhatsApp deliveries were performed.

## Deployment notes

Provide `WA_API_TOKEN`, confirm the configured gateway's response contract and use the canonical public Google callback URL. Refresh the application's configuration cache through the normal deployment procedure. The existing lockfile requires PHP 8.4 even though the broad constraint in `composer.json` permits older versions; use a compatible runtime when installing this lockfile. No database migration is introduced by these fixes.

Existing stored data is not automatically rewritten. Review any suspected historical numeric or region inconsistencies against the original source before correcting them.
