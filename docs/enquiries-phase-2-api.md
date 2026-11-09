# Internal Staff Enquiry API — Phase 2

The API uses existing enquiries and Victoria records. It does not create another
enquiry system, change the frontend, or send staff notifications.

## Authentication, authorisation and deployment

Every route uses the existing `auth:staff_sanctum`, `staff.active`, and
`staff.permission` middleware. Send `Accept: application/json` and a staff bearer
token from `/api/staff/auth/login`. Actors must be active and email-verified.
Client tokens cannot authenticate to these endpoints. Permissions come from the
staff role/permission relations, including custom roles, not role-name checks.

| Method | Route | Required permissions |
| --- | --- | --- |
| GET | `/api/staff/enquiries` | `enquiries.view` |
| GET | `/api/staff/enquiries/counts` | `enquiries.view` |
| GET | `/api/staff/enquiries/{enquiry}` | `enquiries.view` |
| PATCH | `/api/staff/enquiries/{enquiry}/assignment` | `enquiries.view`, `enquiries.assign` |
| PATCH | `/api/staff/enquiries/{enquiry}/classification` | `enquiries.view`, `enquiries.classify` |

`{enquiry}` is the existing enquiry UUID, not a conversation UUID or reference.
The static counts route precedes the UUID route.

Existing view/assign permissions are reused. `enquiries.classify` is new and is
seeded for `super_admin` and `enquiry_team_manager`. Basic, reporting-only, member
and solicitor roles do not receive classification management permission. Existing
view/assign grants are retained. The existing `StaffRolesAndPermissionsSeeder`
remains the source of idempotent role configuration. Super Admin has all three.

Deploy these migrations before enabling the new code:

- `2026_11_09_000004_add_staff_actor_to_enquiry_events.php`: nullable indexed
  `performed_by_staff_id -> staff_users.id`, delete sets null. Existing
  `performed_by -> users.id` client actors are preserved. SQLite uses inline
  ADD COLUMN to avoid rebuilding historical event rows.
- `2026_11_09_000005_sync_staff_enquiry_permissions.php`: runs the existing
  idempotent role/permission seeder. Permission data is retained on rollback,
  following existing staff migrations.

These were tested on disposable SQLite databases; no staging/production migration
or backfill was run during implementation. PostgreSQL row-lock scheduling has not
been integration-tested here.

## Listing

`GET /api/staff/enquiries` returns standard Laravel `data`, `links` and `meta`.
All filters combine with AND; search matches any of the four searchable fields.

| Query parameter | Validation and behaviour |
| --- | --- |
| `page` | Integer >= 1; default 1 |
| `per_page` | Integer 1–100; default 25 |
| `search` | String up to 255 characters; case-insensitive substring of reference, client name, email or phone; literal `%`/`_`, not SQL wildcards |
| `status` | One canonical status below |
| `practice_area` | One canonical practice area below |
| `matter_type` | One canonical matter key below |
| `assigned_staff_id` | Existing staff ID; cannot combine with `unassigned` |
| `unassigned` | Boolean `1`/`0`; true selects NULL assigned_staff_id, false selects assigned records |
| `requires_review` | Boolean `1`/`0`; false is an active filter |
| `office` | Exact string, maximum 255 characters |
| `sort` | Only `created_at`, `submitted_at`, `last_activity_at`, `reference`; default `created_at` |
| `direction` | Only `asc` or `desc`; default `desc` |

An enquiry ID secondary sort makes equal timestamps deterministic. Arbitrary
column names and SQL sorting expressions return 422. Timestamps can be null for
historical records. Sorting of null timestamps follows the database's ordering.

Example: `/api/staff/enquiries?status=submitted&unassigned=1&per_page=25`

A list item has exactly these explicit fields (raw metadata is excluded):

```json
{
  "id": "enquiry-uuid",
  "reference": "PROP-20261109-000001",
  "client_name": "Example Client",
  "client_email": "client@example.com",
  "client_phone": "+447700900123",
  "practice_area": "property_law",
  "matter_type": "conveyancing_purchase",
  "source": "victoria",
  "workflow_key": "victoria_conversation",
  "office": "Bristol",
  "status": "submitted",
  "priority": "normal",
  "assigned_staff_id": null,
  "assigned_staff": null,
  "requires_review": false,
  "classification_confidence": 0.95,
  "classification_source": "victoria",
  "handoff_reason": "sufficient_information_gathered",
  "summary": {
    "text": "Property Law enquiry — Conveyancing Purchase. Contact details available. Awaiting staff assignment.",
    "contact_details_complete": true,
    "source": "operational_enquiry"
  },
  "received_at": "2026-11-09T10:00:00.000000Z",
  "created_at": "2026-11-09T10:00:00.000000Z",
  "submitted_at": "2026-11-09T10:00:00.000000Z",
  "last_activity_at": "2026-11-09T10:00:00.000000Z"
}
```

`received_at` aliases `created_at`; it does not manufacture a receipt timestamp.
`source` is `victoria` for `victoria_conversation`, otherwise `workflow`.
`classification_source` independently describes who last classified the enquiry.
Assigned staff summaries contain only `id`, `first_name`, `last_name`, `name`,
`email`, `status`. A collection response wraps the above object:

```json
{
  "data": ["list item objects as above"],
  "links": {"first": "...page=1", "last": "...page=1", "prev": null, "next": null},
  "meta": {"current_page": 1, "from": 1, "last_page": 1, "links": [], "path": ".../api/staff/enquiries", "per_page": 25, "to": 1, "total": 1}
}
```

## Counts and lifecycle gap

`GET /api/staff/enquiries/counts` returns global counts, independent of list
filters, in one aggregate query. Categories overlap:

```json
{"data":{"all":15,"submitted":8,"unassigned":10,"assigned":5,"requires_review":3}}
```

- `all`: all enquiry records, including drafts and completed questionnaires.
- `submitted`: exactly status `submitted` (not an invented `new` alias).
- `assigned` / `unassigned`: non-NULL / NULL `assigned_staff_id`.
- `requires_review`: flag true.

Current canonical statuses are `draft`, `in_progress`, `submitted`, `completed`.
**Completed means questionnaire completion, not operational closure.** A completed
Will can have `submitted_at` populated while its status remains `completed`; it
counts in all/assignment/review categories but not status-submitted. Closed/lost
outcomes, a closed/lost status-update API, and a unified operational lifecycle for
workflow-completed enquiries are not defined. No closed/lost counts or status
aliases are returned. Status filters for `new`, `closed`, `lost` return 422.

## Detail

`GET /api/staff/enquiries/{enquiry}` returns `{"data": {...}}`. Its data includes
all list fields plus the following explicitly selected fields:

- `user_id`, `conversation_id`, legacy client-user `assigned_to`,
  `recommended_service`, `completion_percentage`, `qualified_at`, `started_at`,
  `completed_at`, `updated_at`.
- `client_account`: `id`, `name`, `email`, `contact_number`, `email_verified_at`,
  or null. It contains only the linked account.
- `classification`: `practice_area`, `matter_type`, `confidence`, `source`,
  `requires_review`, `handoff_reason`.
- `conversation`: `id`, `uuid`, `status`, current operational `summary`, historical
  `original_ai_summary`, actual `conversation_stage`, `created_at`, `updated_at`,
  and `messages`; null if unlinked.
- `messages`: each is `{id, role, content, created_at}`, restricted to user/assistant
  messages belonging to that conversation, ordered by `created_at`, then ID.
  Internal/system messages and message metadata are excluded.
- `events`: activity items ordered by creation timestamp then ID; see audit below.
- `workflow`: `{id, workflow_key, workflow_version, status, current_step_key,
  answered_steps, total_applicable_steps, started_at, completed_at,
  last_activity_at}`, or null. Raw state and answers are not included.

Missing optional relations produce nulls/empty collections. Passwords, remember
tokens, authentication tokens, arbitrary model metadata, memory entities,
credentials and unrelated client/conversation records are never serialized.
Read access to a full linked transcript is intentionally granted by enquiries.view.

## Assignment and unassignment

`PATCH /api/staff/enquiries/{enquiry}/assignment`

```json
{"assigned_staff_id":42}
```

The key must be present. Use `{"assigned_staff_id":null}` for explicit unassignment.
Assignees must exist in staff_users with status active; existence/status are
checked again under a row lock inside the transaction. Client users are not
assignment targets. Unknown request fields are ignored, not mass-assigned.

Success is 200 with the full updated detail resource above. Assignment changes
only `assigned_staff_id`, `last_activity_at`, normal `updated_at`, and the audit
trail. It preserves client `user_id` and legacy `assigned_to`. A first assignment,
reassignment and unassignment record `enquiry_assigned`, `enquiry_reassigned`,
`enquiry_unassigned`, respectively. Repeating the current assignment is a no-op
and does not duplicate the audit trail. Audit failure rolls back the mutation.
No notification is sent.

## Classification correction

`PATCH /api/staff/enquiries/{enquiry}/classification`

```json
{
  "practice_area":"property_law",
  "matter_type":"conveyancing_purchase",
  "requires_review":false,
  "handoff_reason":"staff_classification_confirmed"
}
```

practice_area and requires_review are required; matter_type must be present,
nullable only for unclassified. handoff_reason is optional/nullable. No aliases
are accepted for staff corrections; use canonical keys:

| Practice area | Matter types |
| --- | --- |
| property_law | buying_a_property, conveyancing_purchase, selling_a_property, remortgage |
| family_law | divorce_and_separation |
| wills_and_probate | making_a_will, probate |
| unclassified | null; requires_review must be true |

Allowed reasons: `staff_classification_confirmed`, `classification_requires_review`,
`contact_details_incomplete`, `qualification_confidence_too_low`,
`staff_review_required`. A confirmation reason cannot accompany review=true; a
review reason cannot accompany review=false. Omission/null chooses
staff_classification_confirmed when clear, classification_requires_review for
unclassified, otherwise staff_review_required. Clearing review also requires a
nonblank operational client name and a valid email or 7–15 digit phone number.

Success is 200 with the full updated detail resource. The transaction locks the
enquiry, sets classification_source=staff, clears the obsolete numeric AI
classification_confidence to null, updates last_activity_at and creates
`enquiry_classification_corrected` with staff actor and before/after classification,
review and reason fields. Repeating an identical correction is a no-op. The
previous confidence is retained in the event's before values. Victoria cannot
replace staff classification fields or clear an explicit staff review flag.
Correction does not set qualified_at/submitted_at or change lifecycle status;
it is not a substitute for operational qualification.

## Summary accuracy and audit

The contradiction came from Phase 1 copying the AI memory summary without adding
account contact completeness. The staff API now returns a separate, deterministic
operational summary from enquiry classification, current contact fields, review
and assignment. Its contact completeness follows Phase 1's name plus valid email
or phone rule. It does not silently edit stored enquiry summaries or Victoria
memory. Original AI text remains distinctly labelled original_ai_summary for
historical context; the primary summary is always operational. Staff clients
should render the primary summary and label historical AI text appropriately.

Staff changes use enquiry_events, which supports UUID enquiry IDs. Client actor
history stays in performed_by; new staff actors use performed_by_staff_id. Each
staff event stores enquiry ID, staff ID (also a numeric metadata snapshot), event
type, before/after values and created_at. If staff is deleted the FK becomes null
but the actor ID snapshot and event remain. No message contents or credentials
are copied into change events. Existing staff_audit_logs conventions were
inspected; staff enquiry changes use this enquiry-specific audit trail rather
than duplicating activity in a second system.

Activity response example:

```json
{
  "id":"event-uuid",
  "enquiry_id":"enquiry-uuid",
  "event_type":"enquiry_reassigned",
  "actor_staff":{"id":42,"first_name":"Example","last_name":"Staff","name":"Example Staff","email":"staff@example.com","status":"active"},
  "actor_staff_id":42,
  "actor_client_id":null,
  "before":{"assigned_staff_id":12},
  "after":{"assigned_staff_id":42},
  "from_status":null,
  "to_status":null,
  "step_key":null,
  "created_at":"2026-11-09T10:00:00.000000Z"
}
```

Before/after output is allowlisted by change type. Old workflow activity retains
its ID, type, actors, step/status and timestamp but raw metadata/answers are not
exposed. Actors may be null for automatic or historical events.

## Errors and status codes

All successful endpoints return 200; list pagination uses the standard Laravel
resource collection envelope. Other status codes:

- **401**: missing/invalid staff authentication, including a client bearer token:
  `{"message":"Unauthenticated."}`.
- **403**: missing permission:
  `{"message":"You do not have permission to perform this action."}`.
  Inactive/unverified actors receive `{"message":"Staff account is not active."}`.
- **404**: unknown enquiry UUID (Laravel model-not-found JSON).
- **422**: invalid query or mutation payload, or assignee no longer active.
  Laravel returns a message and per-field errors, for example:

```json
{
  "message":"The matter type must be canonical and belong to the selected practice area; unclassified requires a null matter type.",
  "errors":{"matter_type":["The matter type must be canonical and belong to the selected practice area; unclassified requires a null matter type."]}
}
```

The top-level validation message can summarise multiple field failures; clients
should use errors[field] rather than match message text. An audit/database failure
returns 500 and the transaction rolls back. Use production APP_DEBUG=false to
avoid framework exception detail.

## Changed files

- routes/api.php
- config/staff_permissions.php
- app/Enums/EnquiryStatus.php
- app/Http/Controllers/Api/V1/Internal/EnquiryController.php
- app/Http/Requests/Internal/{ListEnquiriesRequest,AssignEnquiryRequest,CorrectEnquiryClassificationRequest}.php
- app/Http/Resources/InternalEnquiryResource.php
- app/Http/Resources/Internal/{EnquiryListItemResource,AssignedStaffResource,ConversationMessageResource,EnquiryActivityResource}.php
- app/Models/EnquiryEvent.php
- app/Services/Enquiries/{EnquiryClassification,InternalEnquiryService,OperationalEnquirySummary,EnquiryEventService,EnquiryQualificationService}.php
- database/migrations/2026_11_09_000004_add_staff_actor_to_enquiry_events.php
- database/migrations/2026_11_09_000005_sync_staff_enquiry_permissions.php
- tests/Feature/InternalEnquiryTest.php
- docs/enquiries-phase-2-api.md

## Validation results

| Command | Tests | Assertions | Result |
| --- | ---: | ---: | --- |
| `php artisan test --filter=InternalEnquiry` | 27 | 288 | Passed |
| `php artisan test --filter=Enquiry` | 67 | 507 | Passed |
| `php artisan test --filter=Staff` | 85 | 332 | Passed |
| `php artisan test` | 161 | 812 | Passed |

Pint `--test`, `git diff --check`, and route/middleware inspection passed.
Filters overlap (for example, some internal enquiry test names contain Staff).
Coverage includes all supported filters, safe sorting, permissions, client-token
rejection, active/verified actors, ordered linked messages, sensitive-field
exclusion, staff mutations, audit rollback, historical actor migration, summary
preservation, independent mutation permissions and existing Phase 1 tests.
