# Pitch notification operations

Development integration only. No live migration, delivery, configuration switch,
scheduled job, deployment or commit was performed for this work. Overall module
requirements and acceptance remain in CLUBUNIO-PITCH-PLANNING.md.

## Transaction and recipients

The core calls `PitchPlanningNotificationService::record(Tenant,array)` inside
its source transaction. A unique tenant/event key, fingerprint and locked event
row prevent repeated fanout. Source writes, notifications, mobile inbox entries
and real mobile push outbox records commit or roll back together.

Only new friendly requests prepare email snapshots for active personal admin or
office accounts with valid email usernames. Addresses are deduplicated. Decisions
notify only the requester; moves notify active affected-team staff with approved
self links and portal access, regardless of staff function or profile role.
Team-linked manual creation and cancellations notify the union of affected staff
and the requester, once per account. Creation without explicit team IDs does not
fan out; a cancellation can still reach its requester without a team assignment.
Both events are push/in-app only. Area configuration does not create notices.
The same action routing covers SFV creation/withdrawal and field closures when
core supplies explicit affected team IDs; closure events use the teams of
intersecting approved occupancies, not guessed team names.
Profile membership alone never creates a recipient. Moves show previous and new
field, date and time; lookup labels use each recipient's template language.

Existing `MobileAccess` still rejects dynamic-profile mobile entry. Such linked
staff can receive stored notification/inbox records, but their mobile push
enqueueing and mobile inbox access remain blocked. This integration does not
silently widen membership, session or planner rights. Device/session revocation,
push opt-in and fresh target authorization still apply to all other recipients.

## Explicit test email worker

The command is registered through Laravel's normal command discovery:

```text
php artisan clubunio:deliver-pitch-mail --dispatch --tenant=TENANT_ID --limit=20
```

Use an optional `--event=EVENT_ID` to select one event. Tenant and event IDs must
be positive; the limit is 1 to 50. Without `--dispatch`, the command fails closed.
No scheduler entry was added.

Both `clubunio-integrations.test_mail_enabled` and
`clubunio-integrations.pitch_planning_test_mail_enabled` must be true, and the
application environment must be local, testing or staging. The existing private
integration configuration reads `TEST_MAIL_ENABLED` and the new default-off
`PITCH_PLANNING_TEST_MAIL_ENABLED`; neither value was enabled by this work.
SMTP configuration and `clubunio_test` remain the existing mail path.

`TestOutboxDispatcher` always addresses this work to `TestMailGuard::RECIPIENT`
(currently `admin@svsissach.ch`), never the snapshot's intended addresses. The
existing mail guard also strips CC/BCC, redirects reply-to and prefixes the test
subject. This is an executable test-delivery path, not authorization to send
business email directly to office accounts.

New complete request email snapshots are `ready`; requests without a usable
template/address remain `held`. The guarded worker can also consume earlier
`held` request snapshots that contain encrypted mail and a completed fanout
marker. Other actions, incomplete events and absent payloads are never selected.
No send runs from a source request or inside an open business transaction.

## Failure handling

Claiming uses `FOR UPDATE SKIP LOCKED`, changes the event to `sending`, and commits
before calling the mailer. Accepted events are not selected again. Exceptions,
invalid payloads, rejected transport results and partial multi-recipient sends
become `attention` with `SMTP_SEND_FAILED`. The command exits unsuccessfully when
an attempted event needs review. A process interruption may leave `sending`.
Neither `attention` nor `sending` is automatically replayed, and a domain retry
does not reset these states. Inspect provider evidence before any separately
authorized manual recovery; delivery may already have happened.

## Local verification

`tests/Unit/PitchPlanningNotificationServiceTest.php` uses isolated SQLite data,
the actual core notification hook and actual mobile enqueueing. Mail-worker tests
use the real Laravel mail guard with an in-memory array transport, or explicit
transport mocks for ambiguous failures. No SMTP/FCM call is made. Coverage includes
commit boundaries, rollback, duplicate suppression, recipient languages, command
guards, bounded scope and non-replay.

`tests/Feature/PitchPlanningNotificationMigrationTest.php` also passed against
PostgreSQL: captured migration DDL was rewritten to `pg_temp`, with temporary
parents cloned using `LIKE ... INCLUDING ALL`, then rolled back. It verifies
event uniqueness, tenant FKs, email-action constraints and preservation of push
queue FKs/uniqueness while extending the kind check. This does not apply the
permanent migration or exercise template seeding against real tenants. Concurrent
worker contention and real device delivery remain separate integration checks.
