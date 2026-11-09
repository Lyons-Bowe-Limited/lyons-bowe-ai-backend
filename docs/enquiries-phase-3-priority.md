# Phase 3: Victoria urgency and enquiry priority

## Cause and scope

The normal-priority result occurred because structured conversation extraction
had no urgency contract, qualification did not map urgency into enquiry priority,
and the existing Will urgency branch only saved an answer. Consultation escalation
signals did not update operational priority. Enquiries therefore retained their
normal default despite the client's stated urgency.

This phase adds structured urgency extraction and a priority service to the
existing enquiry pipeline. No frontend, notifications, duplicate enquiry system,
status lifecycle changes or background scheduling are introduced.

## Deployment and preservation

Apply these migrations before deploying the new code:

1. `2026_11_09_000006_add_enquiry_priority_metadata.php` adds nullable fields:
   - priority_source: victoria or staff when assessed; null for unassessed/history.
   - priority_reason: machine-readable Victoria reason or staff reason (500 chars).
   - priority_confidence: decimal(5,4), 0–1; staff judgements use null.
   - priority_set_at: timestamp with time zone.
   - priority_set_by_staff_id: FK to staff_users, delete sets null, indexed.
   - Composite index (priority, priority_set_at).
   - urgency JSON on ai_conversation_memories, nullable for existing records.
2. `2026_11_09_000007_sync_staff_priority_permission.php` reuses the idempotent
   StaffRolesAndPermissionsSeeder. Permissions are retained on rollback.

The existing priority column and existing records/audit rows are preserved by
these additive migrations. SQLite adds the nullable staff FK inline to avoid a
rebuild cascading into historical events. No staging/production migrations or
bulk data rewrites were run during development.

New writes accept only normal, high, urgent, via EnquiryPriority. The old client
start-enquiry request's low option has been removed, and service creation also
validates the enum. Historical priorities are not rewritten by the migration.
Metadata can remain null for unassessed historical or unfinished workflow records;
equal existing high/urgent priority is not retroactively attributed to Victoria.

## Structured memory

Extraction now returns urgency independently from summary text:

```json
{
  "urgency": {
    "signals": [
      {
        "type": "court_deadline",
        "confidence": 0.95,
        "immediate": null,
        "deadline_at": "2026-11-10T12:00:00+00:00"
      }
    ]
  }
}
```

Allowed signal types:

- explicit_urgent: client explicitly asserts/requests urgency for their matter.
- immediate_safety: immediate safety/safeguarding concern, immediate=true.
- court_deadline, hearing_date, completion_deadline, other_deadline.
- medical_deadline: medical circumstances relevant to a Will; mapping requires
  the operational enquiry practice area to be wills_and_probate.
- time_sensitive_circumstance: another explicitly time-sensitive material situation.

Only type, confidence, immediate and deadline_at are retained. Unknown signal
types, invalid confidence scores and arbitrary text/metadata are discarded.
At most 20 signals are stored. Immediate must be a true boolean or null; strings
are not interpreted as booleans. Deadlines accept ISO timestamps with offsets or
YYYY-MM-DD. Date-only deadlines are treated as end of day in the configured
Europe/London timezone. Invalid or unanchored dates are not guessed.

Claude receives the current reference time, existing structured urgency and
recent chronological history, with instructions to use client statements rather
than assistant suggestions, hypothetical questions, emotional language or summary
text. Relative dates must be anchored to the supplied reference time.

A narrow deterministic fallback also recognises direct assertions such as
“This is urgent” and “I need urgent legal help” from the latest user message.
It does not inspect summaries or assistant messages. Negations, hypothetical
questions and emotional distress alone do not constitute urgency. This fallback
still runs if context extraction returns no data. Broader language relies on the
structured extraction. Same-second history ordering uses message ID to retain
the current turn reliably.

## Mapping and safeguards

`config/enquiry-priority.php` owns:

- confidence_threshold: initially 0.80.
- imminent_hours: initially 72.
- timezone: Europe/London.
- allowed signal types and supported urgent Will answer keys.

For signals meeting the confidence threshold:

| Signal | Priority |
| --- | --- |
| Direct explicit urgent request | urgent |
| Immediate safety/safeguarding concern | urgent |
| Material future deadline within the imminent window (inclusive) | urgent |
| Reliable future deadline outside the window | high |
| Explicitly stated immediate material circumstance, date unknown | urgent |
| Explicitly stated non-immediate material circumstance, date unknown | high |
| Unknown immediacy/date, invalid/expired date or no reliable signal | normal candidate |
| Positive urgent Will answer | urgent, reason urgent_will_required, confidence 1 |

The highest supported candidate wins, then confidence breaks equal-rank ties.
A past date is not treated as an imminent future deadline. Unknown dates are not
reconstructed from summary text. An explicitly stated immediate circumstance can
be used without inventing a date; a known past date is not promoted by that flag.

Victoria reasons are the signal-type keys, urgent_will_required, or
no_reliable_urgency_signal (confidence 0). The numeric confidence is the retained
signal's confidence, not a calibrated probability of legal risk.

Qualification locks/reloads persisted memory and the existing enquiry. Existing
enquiries are checked for priority signals even when a later message does not
qualify again. New records are only created by the existing qualification rules.
The priority service never creates enquiries. It reloads/locks the enquiry before
updating it, allowing normal -> high -> urgent or normal -> urgent. It never
lowers an existing high/urgent priority, replaces a staff override, or changes
staff assignment/classification. Repeated equal signals do not duplicate events
or reset priority_set_at. An initial normal assessment populates metadata without
a priority_raised event.

## Will workflow

The current configured question/step key is urgent_will. Some references and
historic records use urgent_will_required; both are supported without renaming
answers or changing answer revision behaviour. A validated positive boolean
answer updates that same enquiry immediately inside the answer transaction,
with priority_source=victoria and priority_reason=urgent_will_required. Negative
answers do not raise or lower priority. Staff overrides still take precedence.

Already saved positive answers are checked on subsequent workflow updates,
completion and ordinary qualification. This allows pre-Phase-3 Will enquiries to
be corrected on their next update without a duplicate record or bulk backfill.
The latest matching answer is used, preferring normalised boolean values.

## Staff API

`PATCH /api/staff/enquiries/{enquiry}/priority`

Requires existing staff_sanctum authentication, active/verified staff middleware,
and both enquiries.view and enquiries.prioritise. The latter permission is new;
Super Admin and enquiry team managers receive it through the existing seeder.
Basic users and other existing roles are not granted it. Custom permission-based
roles work without role-name checks in the API.

```json
{"priority":"urgent","reason":"Court deadline confirmed for tomorrow."}
```

Only validated priority/reason fields are used. Priority must be normal, high or
urgent; reason is required, trimmed, 3–500 characters. Extra fields cannot spoof
the source or actor. Success is 200 with the updated internal enquiry detail
resource, for example these priority fields within data:

```json
{
  "priority":"urgent",
  "priority_source":"staff",
  "priority_reason":"Court deadline confirmed for tomorrow.",
  "priority_confidence":null,
  "priority_set_at":"2026-11-09T12:00:00.000000Z",
  "priority_set_by_staff_id":42
}
```

Staff may raise or lower priority. A transaction sets source=staff, records the
actor FK/time and last_activity_at, and writes an audit event. Audit failure rolls
back the change. Identical priority/source/reason submissions are no-ops. Later
Victoria updates, positive Will answers and staff account deletion do not release
the override. Another authorised staff override may change it.

Response codes follow Phase 2: 401 for missing/client authentication, 403 for
inactive staff or missing permission, 404 for unknown enquiry, 422 for validation.
Example validation envelope:

```json
{"message":"The selected priority is invalid.","errors":{"priority":["The selected priority is invalid."]}}
```

Clients should use errors[field], not match validation message text. Existing list
and detail resources now also include priority_source, priority_reason,
priority_confidence and priority_set_at beside priority. Detail additionally
includes priority_set_by_staff_id. No frontend integration is included.

## Audit events

- priority_raised: source victoria, before/after priorities, machine reason,
  confidence and creation timestamp; no full user messages or summary text.
- priority_changed_by_staff: existing staffChange audit conventions, staff actor
  FK and ID snapshot, before/after priority/source/reason/confidence and timestamp.

The internal activity resource explicitly allows those priority fields. Staff
reasons are supplied by staff; no transcript is automatically copied into events.
Events stay in enquiry_events. The staff setter FK may become null on deletion,
but priority_source remains staff and the event retains the actor ID snapshot.

## Limitations

Structured language/date extraction remains model-dependent. The deterministic
fallback is intentionally narrow; unsupported languages, ambiguous urgency,
unknown dates and low-confidence signals are not automatically escalated.
There is no summary-text scan or historical conversation replay. Existing Will
answers are rechecked on the next relevant update, not in a migration.

No timer/job raises a high priority as a deadline approaches: re-evaluation occurs
on conversation/workflow updates. Expired deadlines require staff judgement.
Priority is an operational triage signal, not a determination of legal deadlines.
Legacy/unassessed metadata is nullable. The existing Phase 2 closed/lost lifecycle
gap is unchanged. PostgreSQL-specific concurrent lock scheduling remains
unverified by the disposable SQLite tests.

## Changed files

- app/Enums/EnquiryPriority.php
- app/Models/{Enquiry,AiConversationMemory}.php
- app/Services/{ClaudeService,ConversationContextService}.php
- app/Services/Enquiries/{UrgencyDetectionService,EnquiryPriorityService,EnquiryCreationService,EnquiryQualificationService,EnquiryWorkflowService,EnquiryEventService}.php
- app/Http/Requests/Enquiries/StartEnquiryRequest.php
- app/Http/Requests/Internal/ChangeEnquiryPriorityRequest.php
- app/Http/Controllers/Api/V1/Internal/EnquiryController.php
- app/Http/Resources/Internal/{EnquiryListItemResource,EnquiryActivityResource}.php
- app/Http/Resources/InternalEnquiryResource.php
- config/{enquiry-priority,staff_permissions}.php
- routes/api.php
- database/migrations/2026_11_09_000006_add_enquiry_priority_metadata.php
- database/migrations/2026_11_09_000007_sync_staff_priority_permission.php
- tests/Feature/EnquiryPriorityTest.php
- docs/enquiries-phase-3-priority.md


## Validation results

| Command | Tests | Assertions | Result |
| --- | ---: | ---: | --- |
| `php artisan test --filter=EnquiryPriority` | 29 | 152 | Passed |
| `php artisan test --filter=Enquiry` | 96 | 659 | Passed |
| `php artisan test --filter=InternalEnquiry` | 27 | 288 | Passed |
| `php artisan test --filter=Staff` | 91 | 375 | Passed |
| `php artisan test` | 190 | 964 | Passed |

Pint `--test`, route/middleware inspection and `git diff --check` passed. Test
filters overlap. All data mutations during validation used disposable SQLite
fixtures, including migration preservation and audit-rollback regressions.
