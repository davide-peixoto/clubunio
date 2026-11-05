# Mobile facility overview and fixed planner axis

## Scope (05.11.2026)

- App `Status Anlagen` starts with the existing open/blocked overview. Status,
  day calendar and requests remain selectable at the top.
- Planning API requests are deferred until the calendar/requests is opened.
  Explicit team-calendar and notification destinations still open their date.
- Mobile day planner counter-translates the opaque time axis against horizontal
  scrolling. Vertical movement remains shared with bookings, including large text.
- Personal status reading uses the same decision for module negotiation and GET;
  facility editing/status definitions keep their existing separate permissions.
- No user assignments, database migration, imports, provider activation or
  PHPMaker DEV/PROD changes. Existing private Firebase registry must be preserved.

## Verification

Mobile commit `baae0db`, synchronized to origin after incorporating the parallel
Xcode metadata update.56 targeted Flutter tests passed (planner, navigation,
notification targets, personal facilities and original native modules).
`flutter analyze`: no issues. DE/EN 320px/large-text position checks confirm the
time axis remains left and vertically aligned after scrolling. No new UI strings.
An updated installed iOS/Android test build is required; no store release or
physical-device acceptance claimed.

## Backend Release Procedure

Candidate `20261105-clubunio-facilities-status`, based on
`20261105-clubunio-pitch-usability-v3`. Four-file hash-checked delta: two mobile
runtime classes and two private CLI checks. `release-facilities-status.sh`
stages an inactive copy, compares complete normalized cached configuration,
preserves `.env`/Firebase registry, rebuilds release-local caches, performs
read-only own-account and trainer checks, then atomically switches the symlink.
No login token or real message is created for the trainer check.

Rollback: point `/var/www/svs-laravel/current` back to the preserved
`20261105-clubunio-pitch-usability-v3` release and reload PHP-FPM; no data rollback.
Older app builds can receive the corrected status tab but need the new app
build for default selection and the fixed time axis.

## Activated And Verified

Active release: `20261105-clubunio-facilities-status`. Manifest SHA-256:
`3fc0d6abb994b9529da35d3aec490db069dc67615491bd1c5bfa6572e6d2b915`.
Four deployed hashes verified before activation; normalized cached configuration
fully equal, including private Firebase registry. PHP-FPM active, HTTPS health
200, anonymous facilities API401. Both push flags remain false.

39 backend tests /686 assertions passed: MobileFacilitiesBusTest,
MobilePitchControllerTest and MobileReadContextTest. PostgreSQL fixtures rolled
back; provider requests prohibited.2670 DE/EN translation keys checked.
See CLUBUNIO-MOBILE-FACILITIES-ACCESS.md for authorization coverage.

Read-only server check with one eligible existing trainer: module reports
facilities=true and pitch_planning=true, three real fields, all can_edit=false.
Own-account/old-client compatibility check also passed. No real login/session
creation, status mutation, notification or user-right update. A native signed-in
check on the user's device remains outstanding; this is not a device test.
