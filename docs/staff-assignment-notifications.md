# Staff assignment notifications and My Work

## Existing schema inspection

The repository's legacy notifications migration declares id, type, title,
message, optional data/read_at and timestamps. It has no authenticated recipient
column, no staff FK, and no application database notification model. The existing
ResetPasswordNotification/VerifyEmailNotification classes are mail notifications,
not a compatible owner-scoped inbox. Reusing that table would not safely isolate
staff recipients.

This change therefore adds StaffNotification / staff_notifications. It does not
replace enquiries or the existing assignment pipeline, modify the legacy table,
override Notifiable's generic notifications relation, or send email/push messages.
StaffUser::staffNotifications() is a separate, explicit recipient relation.

## Migration

Apply `2026_11_10_000002_create_staff_notifications_table.php` before deploying the
assignment code. It creates:

| Field | Type and behaviour |
| --- | --- |
| id | UUID primary key |
| staff_user_id | Required staff_users FK; cascade on staff deletion |
| type | Required string |
| title | Required string |
| message | Required text |
| enquiry_id | Nullable UUID FK to enquiries; set null on enquiry deletion |
| read_at | Nullable timezone-aware timestamp |
| created_at, updated_at | Timezone-aware timestamps |

Indexes cover recipient/created_at/id, recipient/read_at and enquiry_id. No
existing records or legacy notifications are migrated/backfilled. Deleting an
enquiry retains its historical notification text with enquiry_id=null. Deleting
a staff account removes its private inbox. No staging/production migration was
run during implementation.

## Assignment transaction

The existing InternalEnquiryService::assign() locks the enquiry, validates/locks
the active target staff account, updates assigned_staff_id and last_activity_at,
and writes its existing enquiry event. It now also inserts one recipient-owned
notification inside the same transaction when the assignee changes to a non-null
staff ID:

- type: enquiry_assigned.
- title: Enquiry assigned to you.
- message: Enquiry [reference] for [client name] was assigned to you.
- enquiry_id: the existing enquiry UUID.

An unavailable client name is represented as “a client”, without inventing a name.
Reassignment notifies the new recipient only. Repeating the current assignee is
a no-op, preserving notification and audit counts/timestamps. Unassignment sends
no assignment notification. A genuine later reassignment back to an earlier
assignee is a new assignment and creates a new alert.

All writes commit or roll back together. Failure after an alert insert rolls
back that alert, the assignee change and its audit event. No asynchronous queue
or external delivery can leave an alert for a rolled-back assignment. Existing
enquiry_assigned/enquiry_reassigned/enquiry_unassigned events remain unchanged.
Client user_id and legacy assigned_to are untouched.

Historical alerts remain in a previous recipient's inbox after reassignment or
unassignment. They describe an assignment event, not a current access grant.
Opening the enquiry still requires current assignment/own-work permission or
global enquiry view permission; reading a notification never grants access.

## My Work

The existing routes are retained:

- GET /api/staff/my-work/enquiries
- GET /api/staff/my-work/counts

Both require staff_sanctum authentication, active/verified staff and work.view-own.
They use assigned_staff_id = authenticated staff ID before filtering/counting.
Client parameters cannot change ownership. The safe pagination/search/filter and
sorting contract remains as documented in [My Work security](enquiries-my-work-security.md).
After an assignment commit, the next API read immediately reflects the new owner;
reassignment and unassignment remove it from the old owner's queue. No frontend
refresh, websocket or polling implementation is included.

Own-work counts now include a real draft total, while retaining existing fields:

```json
{"data":{"all":2,"submitted":1,"draft":1,"assigned":2,"unassigned":0,"requires_review":1}}
```

There is no supported operational due-date/overdue model, so overdue is omitted.
AI urgency dates are not repurposed into overdue work. The global counts contract
is unchanged. No new status values or lifecycle rules are introduced.

## Notification routes and access

All four routes use the existing staff_sanctum and staff.active middleware. No new
permission is introduced for a personal inbox: active, verified staff may read
and mark only their own alerts, even without management permissions. Assignment
still requires the existing enquiries.view and enquiries.assign permissions.

| Method | Route | Response |
| --- | --- | --- |
| GET | /api/staff/notifications | Paginated own notifications |
| GET | /api/staff/notifications/unread-count | Own unread count |
| PATCH | /api/staff/notifications/{notification}/read | Updated own notification |
| PATCH | /api/staff/notifications/read-all | Number of own unread rows marked |

{notification} is a UUID. Another staff member's UUID returns 403, matching the
project's known-resource forbidden convention. Unknown/invalid UUIDs return 404.
Super Admin also cannot read/update another person's inbox. Client tokens return
401; inactive/unverified staff return 403. No route lists global notifications.

Listing defaults to page=1, per_page=25 (maximum 100). Records are ordered by
created_at descending, then ID descending for deterministic ties. Optional unread=1
selects unread rows; unread=0 selects read rows. Invalid pagination/booleans return
422. Nonempty staff_user_id/staff_id/user_id list parameters return 422. Other
parameters never override the authenticated relationship scope.

Read/count actions derive their recipient from the authenticated staff relationship
and ignore attempts to supply a different staff ID or read timestamp. Read timestamps
use server time. Marking an already-read notification is idempotent; mark-all touches
only unread rows and preserves prior read timestamps.

### Responses

List uses standard Laravel data/links/meta pagination. Each item contains only:

```json
{
  "id":"notification-uuid",
  "staff_user_id":42,
  "type":"enquiry_assigned",
  "title":"Enquiry assigned to you",
  "message":"Enquiry PROP-20261110-000001 for Example Client was assigned to you.",
  "enquiry_id":"enquiry-uuid",
  "read_at":null,
  "created_at":"2026-11-10T10:00:00.000000Z",
  "updated_at":"2026-11-10T10:00:00.000000Z"
}
```

Unread count returns `{"data":{"unread_count":2}}`. Mark-one returns
`{"data":{...notification fields...}}`. Mark-all returns
`{"data":{"marked_read":2}}`, then 0 on an unchanged repeat. Success is 200.
No tokens, passwords, arbitrary metadata, message transcripts or unrelated
account records are serialized. Validation uses Laravel's standard 422
message/errors envelope.

## Files

- database/migrations/2026_11_10_000002_create_staff_notifications_table.php
- app/Models/StaffNotification.php
- app/Models/StaffUser.php
- app/Services/Enquiries/InternalEnquiryService.php
- app/Services/Enquiries/EnquiryQueueQuery.php
- app/Http/Controllers/Api/V1/Internal/MyWorkEnquiryController.php
- app/Http/Controllers/Api/V1/Internal/StaffNotificationController.php
- app/Http/Requests/Internal/ListStaffNotificationsRequest.php
- app/Http/Resources/Internal/StaffNotificationResource.php
- routes/api.php
- tests/Feature/StaffAssignmentNotificationTest.php
- tests/Feature/StaffMyWorkEnquiryTest.php
- docs/staff-assignment-notifications.md
- docs/enquiries-my-work-security.md

## Validation

| Command | Tests | Assertions | Result |
| --- | ---: | ---: | --- |
| `php artisan test --filter=StaffAssignmentNotification` | 15 | 156 | Passed |
| `php artisan test --filter=StaffMyWorkEnquiry` | 14 | 172 | Passed |
| `php artisan test` | 228 | 1377 | Passed |

Pint `--test`, route/middleware inspection and `git diff --check` passed. Tests
cover both rollback before notification creation and rollback after insertion,
failed reassignment, no-op/cyclic assignment, inbox isolation, unread/read actions,
active/verified staff, client-token rejection and historical FK behaviour.
All test writes used disposable SQLite databases; no live assignments or inboxes
were modified. PostgreSQL-specific lock scheduling was not integration-tested.
