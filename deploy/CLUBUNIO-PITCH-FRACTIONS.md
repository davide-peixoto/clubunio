# Pitch thirds and quarters (05.11.2026)

## Behaviour

SV Sissach's three active fields offer whole pitch, Half A/B, Third A/B/C and
Quarter A/B/C/D. Whole pitch remains a null area_id; partial areas use existing
tenant/field-scoped pitch_areas IDs. No schema or runtime change. Existing
bookings, requests, closures and automatic half-training defaults are unchanged.

Thirds partition x at 0, .333333, .666667 and 1, matching database precision
without gaps or overlaps. Quarters use a 2x2 grid: A/B subdivide Half A along y,
C/D subdivide Half B. Labels do not imply compass directions. Rectangle conflict
checks handle mixed halves/thirds/quarters, whole bookings and closed fields.

Dynamic DE/EN labels: Drittel/Third and Viertel/Quarter, separately stored.
No translation key or free-text replacement needed. Web planning, weekly
training choices and the mobile pitch API already read these areas dynamically.
The current ClubUnio app needs a fresh planning load, not a new build for this
addition. Physical-device verification remains outstanding.

## Repeatable Migration

Definitions: deploy/pitch-fraction-presets.php.
CLI helper: deploy/configure-pitch-fractions.php, never publish as a web tool.

1. Run --preview against the exact ClubUnio PostgreSQL database.
2. Review field IDs and missing count; retain snapshot_sha256.
3. Run --rollback-test --expected=HASH --actor=EXISTING_ADMIN.
4. On ClubUnio run --apply with the same fresh snapshot and actor.
5. Run --verify: no missing areas, valid localized Web/mobile projections.

Outside the application tree use --root=/var/www/svs-laravel/current. Only the
current release under /var/www/svs-laravel/releases is accepted. Apply requires
Linux, APP_URL https://dev.clubunio.ch, database db_acdf8d_dclubora and tenant
sv-sissach. A tenant write lock, fresh snapshot hash, encrypted read-back-verified
backup and per-area audit record protect the change. Equivalent geometry is
reused; conflicting labels stop the operation rather than reinterpret custom
areas. Repeated execution adds no duplicates. New fields need another reviewed
preview/apply.

Future imports must preserve area IDs/references. For a fresh migration replay
this additive helper after field import. Never repeat the earlier training-half
assignment to add these choices. Rollback requires reviewed removal of only
new, unreferenced IDs; referenced areas must remain. Do not restore the whole
snapshot over later bookings. Retain audit history and the protected backup.

## Applied Configuration and Checks

- Active code unchanged: 20261105-clubunio-dashboard-reservations-v2.
- Private helper package: /var/www/svs-laravel/shared/pitch-fractions-20261105.
- File hashes checked; no cache flush or PHP restart needed.
- Fields 31/32/33: 21 areas added (IDs83-103), nine partial areas each.
- Before SHA256: 672843b15d50ce536c6a84475879f1590d2673c54c9c15c502807aa1f3aa210e.
- After SHA256: 620bd1e7439e560e6f9f2c5f944c6a2deddd422938133c9eac6cf23c2d36e9b3.
- Backup: shared/backups/pitch-fractions-20261105113033-463bd374f08b9d45.cuenc.
- Backup SHA256: 7adaf9244f098ece9b05dd4fd03013e6e29858f2693863ef215c3fa59f3d597c.
- Local/server rollback rehearsals passed; repeat preview needs zero areas.
- Snapshot checks confirm all existing rows, geometry and bookings unchanged.
- 20 tests / 312 assertions: geometry, cross-partition conflicts, closures,
  PostgreSQL rollback save/read/localization, unchanged half defaults, calendar.
- DE/EN catalog check: 2673 matching keys.
- Authenticated Web form displayed every option; Quarter D selected, then
  cancelled without submitting a request. Server mobile/Web projection checked.
- No actual push/email, approval or booking. PHPMaker DEV/PROD, account rights,
  Firebase flags and hosting configuration untouched.
