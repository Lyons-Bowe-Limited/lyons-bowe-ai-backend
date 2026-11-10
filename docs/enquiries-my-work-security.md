# Global Enquiries and My Work separation

## Permissions and deployment

Runtime access uses the existing staff authentication, active/verified middleware
and StaffAuthorisationService. No queue or detail authorisation checks a role name.
Role names below are the existing names inspected in config/staff_permissions.php
and StaffRolesAndPermissionsSeeder; they are permission configuration, not runtime
role-based exceptions.

The new canonical permission is `work.view-own`. Roles already granted work.view
receive it: super_admin, enquiry_team_manager, enquiry_team_member, solicitor,
legacy team_member and basic. reporting_only is not granted it. work.view remains
for existing feature compatibility; it does not authorise these own-enquiry APIs.
Basic gains assigned-only read access, not global or mutation permissions.

The solicitor role no longer receives enquiries.view and is not granted
enquiries.assign or enquiries.classify. Other existing global/mutation grants,
including Super Admin's, are retained. Staff with multiple/custom roles are
assessed by their actual combined permissions: a separate legitimate global-view
grant continues to allow global access, without a solicitor role-name veto.

Deploy `2026_11_10_000001_sync_staff_own_work_permissions.php` with the new code.
It reloads the deployed permission configuration directly (rather than trusting
a stale configuration cache), runs the existing idempotent seeder, creates
work.view-own once, and synchronises
known role pivots. This removes stale global grants from the solicitor role in
deployed databases without changing staff assignments or enquiry/audit records.
Rollback deliberately does not re-grant the insecure global access. Custom roles
are not rewritten by the configured-role seeder.

**Applying this migration is required:** updating route code/config alone does not
remove an existing database permission grant. No staging/production migration was
run during development.

## Routes and authorization

All routes remain under auth:staff_sanctum and staff.active. Client bearer tokens,
inactive staff and unverified staff are rejected as before.

| Method | Route | Required access |
| --- | --- | --- |
| GET | /api/staff/enquiries | enquiries.view |
| GET | /api/staff/enquiries/counts | enquiries.view |
| GET | /api/staff/enquiries/{enquiry} | enquiries.view OR work.view-own plus assigned_staff_id = authenticated staff ID |
| PATCH | /api/staff/enquiries/{enquiry}/assignment | enquiries.view AND enquiries.assign |
| PATCH | /api/staff/enquiries/{enquiry}/classification | enquiries.view AND enquiries.classify |
| PATCH | /api/staff/enquiries/{enquiry}/priority | enquiries.view AND enquiries.prioritise (unchanged restriction) |
| GET | /api/staff/my-work/enquiries | work.view-own; always authenticated staff scope |
| GET | /api/staff/my-work/counts | work.view-own; always authenticated staff scope |

An existing enquiry that fails the detail check returns 403, following the
project's existing forbidden-access convention. Unknown/invalid UUIDs return 404.
The check happens before loading transcript/account/workflow/activity resources.
Assignment alone is insufficient without work.view-own. Neither client user_id,
legacy assigned_to nor their possible numeric collision with a staff ID grants
own-work access.

Super Admin retains the global queue and access to any detail through its
permissions. Super Admin's My Work endpoint is still scoped to Super Admin's own
assignments, just like every other staff account.

## My Work listing contract

`GET /api/staff/my-work/enquiries` uses the existing EnquiryListItemResource,
standard Laravel data/links/meta pagination and identical safe filtering/sorting
logic to the global queue via EnquiryQueueQuery. The base query always includes:

```php
where('assigned_staff_id', $request->user()->id)
```

No client parameter substitutes for that authenticated ID. Filters only add AND
conditions. Search OR conditions stay grouped within the owner scope.

Supported parameters:

- page: integer >= 1, default 1.
- per_page: integer 1–100, default 25.
- search: case-insensitive literal substring of reference/name/email/phone, up to
  255 characters. SQL wildcard characters are escaped.
- status, practice_area, matter_type: existing canonical values, unchanged.
- office and requires_review: same validated filters as the global list.
- sort: priority, created_at, last_activity_at or submitted_at only. Other values,
  including reference and raw SQL expressions, return 422.
- direction: asc/desc, default desc; default sort created_at.

Priority-first request: `/api/staff/my-work/enquiries?sort=priority&per_page=25`.
Priority is ranked urgent, high, normal, legacy low, then null/unknown. Ties use
last_activity_at descending then enquiry ID descending. This semantic order is
fixed even if direction=asc is supplied; it is never alphabetical. Legacy low is
supported for reading/sorting, not added to the allowed priority-write values.

Date sorts honour direction=asc/desc (default desc) with an ID ascending secondary
order, preserving their existing tie-break. Omitted sort defaults to created_at.
The global Enquiries queue's separate sort allowlist is unchanged.

Secondary orders make ties deterministic. Nonempty assigned_staff_id,
staff_id, staff_user_id, user_id or unassigned parameters return 422. Empty owner
fields are discarded and cannot change the scope. Unknown/nested parameters are
not used to construct filters; they cannot replace ownership. Invalid sorting,
per-page >100 and invalid canonical filters return 422.

Pagination total and links are computed from the scoped query, not global data.
Resource fields, operational summary behaviour and privacy exclusions follow
[the Phase 2 API contract](enquiries-phase-2-api.md).

## Counts

`GET /api/staff/my-work/counts` returns the existing counts envelope, calculated
from assigned_staff_id = authenticated staff ID before aggregation. Query
parameters are not used to change its staff scope or apply list filters:

```json
{"data":{"all":2,"submitted":1,"draft":1,"unassigned":0,"assigned":2,"requires_review":1}}
```

Unassigned is necessarily zero in this queue; assigned equals all. Empty queues
return zero for every count. Closed/lost outcomes are still unsupported, and
completed retains its existing questionnaire-completion meaning. Global counts
remain exclusively on the enquiries.view-protected global endpoint.

## Assignment behaviour and audit

Assignment mutations are unchanged. Queries use current assignment data, so an
assignment appears for the new owner, reassignment removes it from the old owner,
and unassignment removes it from all My Work queues. Detail access follows the
same ownership change. Existing enquiry_assigned/enquiry_reassigned/
enquiry_unassigned events, actor IDs and before/after values are preserved.
No frontend, client authentication or enquiry lifecycle changes are included.
Assignment notifications are now supported; see [staff assignment notifications](staff-assignment-notifications.md).

## Changed files

- config/staff_permissions.php
- routes/api.php
- app/Http/Controllers/Api/V1/Internal/EnquiryController.php
- app/Http/Controllers/Api/V1/Internal/MyWorkEnquiryController.php
- app/Http/Requests/Internal/ListMyWorkEnquiriesRequest.php
- app/Services/Enquiries/EnquiryQueueQuery.php
- database/migrations/2026_11_10_000001_sync_staff_own_work_permissions.php
- tests/Feature/StaffMyWorkEnquiryTest.php
- docs/enquiries-my-work-security.md

## Validation

| Command | Tests | Assertions | Result |
| --- | ---: | ---: | --- |
| `php artisan test --filter=StaffMyWorkEnquiry` | 14 | 172 | Passed |
| `php artisan test --filter=Enquiry` | 119 | 916 | Passed |
| `php artisan test --filter=Staff` | 105 | 547 | Passed |
| `php artisan test` | 213 | 1221 | Passed |

Pint `--test`, route/middleware checks and `git diff --check` passed. Tests include
permission-based custom/combined roles, stale database grants and cached policy,
owner-parameter spoofing, client/legacy ID collisions, global access restrictions,
assignment movement/audit preservation and inactive/client-token rejection.
All test writes used disposable SQLite fixtures.

### My Work sorting update validation

Accepted example: `GET /api/staff/my-work/enquiries?sort=priority&per_page=25`.
Only priority, created_at, last_activity_at and submitted_at are accepted for
this endpoint. Empty/array-valued sorts and SQL expressions return 422.

`php artisan test --filter=StaffMyWork` passed with 19 tests / 259 assertions,
including semantic priority ordering, null/unknown legacy values, date directions,
deterministic pagination, and ownership/scope-escape regression coverage.
`php artisan test` passed with 233 tests / 1464 assertions. Pint and diff checks
passed. No migration or frontend change is required.
