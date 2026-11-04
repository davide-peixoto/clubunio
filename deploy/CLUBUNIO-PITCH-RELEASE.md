# Pitch: targeted release preparation

Preparation runbook, subsequently executed by the parent for pitch-v1.
Actual results and remaining acceptance: [release follow-up](CLUBUNIO-PITCH-FOLLOWUP.md).
The phase descriptions below preserve the original review sequence; do not
rerun the apply stage against the now-present schema. Existing PHPMaker DEV/PROD and
the imported legacy snapshot are outside scope. Visual acceptance is explicitly
postponed by the user; no login bypass and no claim of visual acceptance.

## Read-only readiness

SSH audit succeeded at 2026-11-04T21:17:12Z, independently confirmed by parent.
Current remains `/var/www/svs-laravel/releases/20261104-clubunio-chat-delta-v1`.
`deploy/audit-pitch-readiness.php` uses a PostgreSQL REPEATABLE READ / READ ONLY
transaction and emits only fixed labels, counts and booleans. No provider calls,
tokens, session/device IDs, credentials or row-level personal data are emitted.
The configured-provider boolean means a validated app registry entry exists;
it does not read credentials or prove FCM connectivity. PgBouncer rejects the
PGOPTIONS startup override here; transaction READ ONLY is used and confirmed.

| Audit | Result |
| --- | --- |
| Missing pitch tables | All eight, including events and request idempotency |
| Migration ledger 001500 / 001600 / 001700 / 001800 | 0 / 0 / 0 / 0 |
| Existing weekly area_id | Absent |
| Push enabled / delivery_enabled / configured provider | false / false / false |
| Davide active device/session bindings / registry-matching bindings | 0 / 0 |
| Native pitch / pitch test mail / AI / keybox provider operation flags | Off |
| Templates / revisions / push outbox | 368 / 368 / 0 |
| Active fields / active training slots | 3 / 640 |
| Active slots without field / without time | 553 / 0 |
| Active competitions / SFV-linked / without mapping | 101 / 99 / 101 |
| Active competitions missing field or time | 0 / 0 |
| Active availability rows blocking training | 2 of 3 |

Source counts cover ALL tenants and ALL dates, not just the current season.
They are inventory findings, not permission to populate fields, change facility
statuses, map historical SFV teams or rewrite data. No automatic data patch is
part of this release. Binding counts mean active personal account, non-revoked
device/binding/session and unexpired session; event-specific entitlement,
verified origin ownership and live provider validity are not established.

The user approved a genuine marked test push only to
`davide.peixoto@svsissach.ch`. It is currently impossible: no configured provider,
no eligible binding, and both push flags off. No send was attempted. Parent's
reviewed `--outbox UUID` worker option is included as code only; this runbook
does not invoke that worker or activate FCM. Later permission/configuration,
device registration and exact outbox recipient review remain separate.

Repeat before changes, from local PowerShell (no server upload):

```powershell
Get-Content -Raw deploy/audit-pitch-readiness.php | ssh -T -o BatchMode=yes admin@svs-pi-server 'php /dev/stdin --read-only'
```

## Review And Package

First finish review of the agents' final code, including the now-present real-core
`authorizeFriendlyRequest` replay predicate and exact weekly/idempotency schema.
Finish the pitch/domain/API/web/notification/weekly/SFV/concurrency tests and
parent's selected-push tests. Run `php tools/check-translations.php`. Preserve
unrelated dirty files; this task does not commit. Parent supplies the final
reviewed commit containing the tooling as well as the product delta.

The packager reads Git blobs, never working-tree runtime bytes or an implicit
HEAD. Its closed path list includes package `1e265a8`, weekly/current delta,
`PitchCompetitionEdits`, `FacilityService`, `MobileTeamsData`, `MobilePushOutbox`
and `DeliverMobilePush`. Unknown additional runtime/dependency changes fail.
Old server bytes must equal the pre-pitch Git baseline (only CRLF/LF equivalence
allowed for this source comparison); before-manifest hashes retain exact raw
server bytes. Any foreign server update in a targeted file stops packaging:
merge/review explicitly, do not weaken the baseline check or overwrite it.
Files outside the overlay remain from the actual server release copy.

```powershell
python tools/package-pitch-dev.py --revision FINAL_REVIEWED_COMMIT --inventory
python tools/package-pitch-dev.py --revision FINAL_REVIEWED_COMMIT --reviewed-sha256 REVIEWED_INVENTORY_HASH
```

The first command emits the exact path/hash inventory and its SHA256; review it
before the second. The second requires read-only SSH and creates only local
`storage/app/pitch-release`. It refuses an existing output directory. The final
commit is not available yet: no actual release archive or final manifest is
claimed. Parent transfers only these generated artifacts to the exact server
directory `/var/www/svs-laravel/shared/pitch-release`, excluding runtime/secrets.
Retain the independently recorded SHA256SUMS hash and manifest hash printed by
the packager; do not establish trust merely by hashing the transferred files.
The package directory and files must be group-readable/traversable by www-data
for the guarded schema runner: `sudo chgrp -R www-data "$package"` and
`chmod -R g+rX "$package"`. They contain reviewed source artifacts, not secrets;
the separate encrypted backup directory remains owned0700/0600.

Current migration pins are exact LF deploy bytes. 1500/1600 were also checked
against `git show 1e265a8:path`; 1700/1800 match their stable local LF bytes.
The final packager refuses any committed migration whose SHA256 differs from
the runner pin. Re-review/update pins if an agent changes a migration before
the final commit. Never let export adopt arbitrary discovered hashes.

## Stage And Snapshot

The following are instructions for the parent, NOT commands already run.
Use one coordinated release window; abort if another release changes current.
The candidate is fixed to `20261104-clubunio-pitch-v1` and must not exist.

```bash
base=/var/www/svs-laravel
package=$base/shared/pitch-release
release=$base/releases/20261104-clubunio-pitch-v1
sums=INDEPENDENTLY_RECORDED_SHA256SUMS_HASH
manifest=INDEPENDENTLY_RECORDED_MANIFEST_HASH
bash "$package/release-clubunio-pitch.sh" --stage "$sums"
sudo install -d -o www-data -g www-data -m 0700 "$base/shared/pitch-backups"
cd "$release"
sudo -u www-data php deploy/prepare-pitch-schema.php --export "--manifest-sha256=$manifest"
```

Stage copies the pinned active release, validates the complete archive before
overlay (exact regular-file inventory, raw SHA, no links/traversal/extras),
preserves ENV/dependencies/shared storage, and builds release-local caches.
Compiled views use `bootstrap/cache/pitch-views`, not the active shared views.
Stage neither migrates nor switches current. Native pitch and all pitch/provider
operation flags must still be off. No package install or broad migrate command.

Export snapshots these existing parents, including all ordered rows and
column/constraint/index/relation metadata: tenants, accounts, training_options,
training_slots, team_seasons, competitions, message_templates,
message_template_revisions, mobile_push_outbox, migrations. It refuses more than
20,000 rows per parent or 64 MB plaintext; increase only after separate review.
The snapshot is encrypted with the existing application key, private0700/0600,
exclusive-created/fsynced and decrypt/hash-readback verified. Record the emitted
backup path and encrypted-file SHA256 outside the package. Do not print/decrypt
the parent rows. Preserve the application key through the existing secure backup
policy; a ciphertext without its key is not a recoverable backup.

## Verify And Apply

```bash
backup=EXACT_EXPORTED_CUENC_PATH
backup_sha=INDEPENDENTLY_RECORDED_BACKUP_SHA256
sudo -u www-data php deploy/prepare-pitch-schema.php --verify-backup "--manifest-sha256=$manifest" "--backup-file=$backup" "--backup-sha256=$backup_sha"
sudo -u www-data php deploy/prepare-pitch-schema.php --apply "--manifest-sha256=$manifest" "--backup-file=$backup" "--backup-sha256=$backup_sha"
sudo -u www-data php deploy/prepare-pitch-schema.php --verify-after "--manifest-sha256=$manifest" "--backup-file=$backup" "--backup-sha256=$backup_sha"
bash "$package/release-clubunio-pitch.sh" --verify "$sums" "$backup" "$backup_sha"
```

Apply requires a matching snapshot no older than30 minutes, unchanged current,
closed flags, exact manifest/pinned migrations, absent reserved ledger entries
and absent new schema. One transaction/advisory lock with bounded waits locks
the parent tables, compares the snapshot, invokes exactly the four `up()`
methods and inserts exactly four ordered ledger rows in ONE new batch.
No Artisan migration discovery, seed/import, broad migration, fallback or retry.

Postchecks require every old parent row and definition unchanged except:

- `training_slots`: one nullable bigint area_id, all old values NULL, exact
  named check/FK/index from001700. No existing weekly field is rewritten.
- `mobile_push_outbox`: only append pitch_planning to the known kind check;
  all outbox rows unchanged.
- Templates/revisions: only exact missing six pitch event families in DE/EN,
  seeded per enabled tenant/channel, preserving existing custom/disabled
  variants and history. Initial new revisions must exactly match their template.
- Ledger: the four exact names, one expected next batch; all other rows unchanged.

All eight new tables must be empty, with validated constraints and no PUBLIC
table grants. Any check failure rolls back the DDL/DML transaction. PostgreSQL
identity sequences may advance despite rollback; do not reset them. The snapshot
is a targeted parent backup, NOT an automatic full-database restore mechanism.
Retain evidence and review after any interruption or uncertain commit; never
rerun apply against already-present schema. Unrelated application activity may
stale the backup: re-export before apply, not force past comparison failures.

The verify stage runs the previous chat-delta regression gates plus the new
read-only pitch route/schema/closed-flag gate. It then verifies parents/ledger
again and issues a ten-minute activation receipt. New pitch behavioral tests
must already have passed separately; the small route gate does not claim those.

## Deliberate Activation

After integration/tests, unchanged-source verification and parent review:

```bash
bash "$package/release-clubunio-pitch.sh" --activate-after-review "$sums" "$backup" "$backup_sha"
sudo systemctl is-active php8.3-fpm
curl --fail --silent --show-error https://dev.clubunio.ch/up
sudo -u www-data php "$release/deploy/check-pitch-release.php" --closed
```

Activation rechecks the fresh receipt, backup/parents/ledger, source hashes,
closed flags and previous-current path before the atomic symlink switch/reload.
It does not enable a flag. Web pitch becomes available through schema readiness
at this deliberate code switch; native pitch is still off. Parent must verify
the login200, anonymous protected API401, existing routes and source manifest.
Visual acceptance remains postponed, explicitly recorded rather than bypassed.

Only after smoke, parent may deliberately enable
`CLUBUNIO_MOBILE_NATIVE_PITCH_PLANNING_ENABLED` in the EXISTING private release
configuration. No tooling here edits ENV. Keep every provider/push/pitch-mail
flag closed. Rebuild config preserving release-local compiled views, then:

```bash
cd "$release"
VIEW_COMPILED_PATH="$release/bootstrap/cache/pitch-views" php artisan config:cache
sudo chgrp www-data bootstrap/cache/config.php
sudo systemctl reload php8.3-fpm
sudo -u www-data php deploy/check-pitch-release.php --native-enabled
php deploy/audit-pitch-readiness.php --read-only --current-pitch
```

Native enablement is a separate deliberate parent operation, not authorization
for FCM/provider setup or a send. Do not run schedule workers/dispatch commands
as smoke tests. If own-account push setup later receives its separate execution
review, recheck the exact selected outbox UUID/account and all live prerequisites.

Rollback is code-only to `20261104-clubunio-chat-delta-v1`, followed by FPM reload.
Retain all additive schema/history; never call down/drop/reset. Do not copy a
new native/provider flag into the rollback release. Parent records the actual
result in PI/roadmap/migration documentation and manages any later commit/sync.

## Preparation Checks And Remaining Gates

Passed locally: PHP lint (three tools), Bash syntax, Python syntax, ten synthetic
archive tests (valid overlay; reject extras/duplicates/links/traversal/ENV/wrong
bytes/destination links), DE/EN checker2589 keys. Read-only remote audit passed.
No real backup/export/apply/stage/verify/activation has been executed. End-to-end
schema/backup verification against the candidate is intentionally still pending.

Remaining: final integration and product tests; final reviewed commit and Git
blob pins/inventory; remote baseline match; artifact transfer; stage; encrypted
export/readback; exact apply/postcheck; old and new release gates; deliberate
activation/HTTP smoke; optional explicit native enable. No auto source-data fix.
Own-account push remains blocked as above. Authenticated visual acceptance is
postponed, not an omitted success criterion disguised as a passed test.
