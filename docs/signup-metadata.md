# Signup Metadata

What is recorded about a registration request, why, and how long it is kept.

## Why it exists

On 23 September 2026 two studio accounts were created minutes apart on
production under the same name, with addresses that looked unrelated to the
studio they claimed to be. There was no way to tell whether the two signups
came from one place.

Nothing in the system could answer it:

- `AuthController::register` recorded no request metadata.
- `profile_views` and `search_impressions` both carry `ip_address`, but were
  empty for these accounts, since neither ever browsed anything.
- The Forge box has no per-site `access_log` directive in
  `/etc/nginx/sites-available/`, and the shared `/var/log/nginx/access.log`
  contained no `POST /api/register` lines.

## What is captured

Two columns on `users`, written once at registration and never updated:

| Column | Type | Source |
|---|---|---|
| `signup_ip` | `varchar(45)` | `$request->ip()` |
| `signup_user_agent` | `text` | `$request->userAgent()`, capped at 1000 chars |

They sit beside the existing `signup_platform`. Capture happens in
`UserService::signupMetadata()`, called from `AuthController::register`, which
is the only self-service signup route. Studio accounts come through it too, via
the `type` parameter.

The other `User::create` paths are not self-service and do not capture:
admin panel creation, inbound email (`InboundEmailController`), artist
onboarding (`ArtistOnboardingService`), and provisional clients
(`UserService::createProvisionalClient`).

`signup_ip` is indexed, since the question this answers is "what else came
from this address", which is a lookup by value.

## Accuracy depends on nothing proxying the API

`app/Http/Middleware/TrustProxies.php` has `$proxies` set to null, so
`$request->ip()` returns the directly connecting address and ignores
`X-Forwarded-For` entirely. That is correct while the API terminates on the
Forge box, which it does: api.getinked.in answers with `server: nginx` and no
CDN or load balancer signature. The frontend on Vercel is separate, and the
browser calls api.getinked.in directly, so registrations never pass through it.

**If a CDN or load balancer is ever put in front of the API, this data silently
becomes worthless** — every row would record that proxy's edge address, and
they would all look identical. At that point `$proxies` must be set to the
proxy's actual addresses or ranges. Never `'*'`, which would let any client put
whatever address it liked in the header and have it recorded as fact.

`RegistrationSignupIpTest::test_it_ignores_a_forwarded_header_from_an_untrusted_client`
pins the current behaviour and is the test that has to change alongside it.

## Retention

Ninety days, then `signup_ip` is cleared. The window is long enough to answer a
report that lands weeks after the accounts were created; past that the address
is personal data with no remaining purpose.

```bash
php artisan signups:prune-ips --dry-run
```

`PruneSignupIps::RETENTION_DAYS` holds the number. The command is scheduled
daily at 03:00 in `app/Console/Kernel.php` and is marked destructive in the
admin command runner, since the clearing cannot be undone.

The sweep runs as a query builder update rather than per-model saves. That
skips the model events that would otherwise re-index every affected artist in
Elasticsearch over a field that is not indexed there. `users.updated_at`
carries `ON UPDATE CURRENT_TIMESTAMP` at the MySQL level, so the statement
pins `updated_at` to its own value: a retention sweep is not an account change.

`signup_user_agent` is not currently pruned. On its own it identifies nobody;
it is the pairing with an address that does the work. Worth revisiting if the
retention position is ever reviewed.

## Deletion

`User` does not use `SoftDeletes` and `UserController::performUserDeletion`
issues a real delete, so both columns go with the account. No separate cleanup
step is needed, and the privacy policy's promise to remove personal data within
30 days of account deletion is satisfied by that alone.

## Exposure

This is operator-facing data for investigating abuse. It is not in any public
response. `UserResource` and `SelfUserResource` are explicit allowlists and
neither includes these fields, and the registration response returns only the
new account's id.

It is visible in the React Admin users resource
(`inked-in-www/nextjs/admin/resources/users.tsx`): `signup_ip` as a column on
the list, and platform, address and user agent read-only on the edit screen.
They are display-only on purpose. `UserController::adminUpdate` writes through
any fillable field present in its payload, so an input there would let an
ordinary admin edit overwrite the record of where the account came from.

## Privacy

Storing an address against an account is a different commitment from using one
transiently for rate limiting, which is all this app did before. Draft policy
wording is in `docs/privacy-and-terms-updates.md`; the live policy at
`inked-in-www/nextjs/pages/privacy.tsx` has not been changed.

Note that `profile_views` and `search_impressions` have kept `ip_address`
indefinitely since December 2025 and January 2026. Whatever retention position
is settled on here arguably applies to those too. That was out of scope for
this change.
