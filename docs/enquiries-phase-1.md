# Operational enquiries: Phase 1

Victoria uses `ai_conversations`, `ai_messages`, and `ai_conversation_memories`.
`enquiries` is the operational source of truth. `conversations`, `messages`, and
`leads` are legacy/unused by Victoria; retain them and their historical migrations.
Their live schemas differ from those migrations. This phase does not reconcile
those legacy tables or delete historical data.

## Schema and deployment

Apply these additive migrations during the coordinated application deployment:

1. `2026_11_09_000001_add_operational_fields_to_enquiries.php`: nullable staff FK
   (delete sets null) and index, matter type, indexed qualification timestamp,
   classification confidence/source, indexed review flag (defaults true), reason.
   Existing indexed `submitted_at` is reused.
2. `2026_11_09_000002_add_unique_conversation_to_enquiries.php`: unique nullable
   `conversation_id`. It checks **conversation ID alone**, across all workflows,
   and fails with the IDs if duplicates exist. It never merges/deletes records.
   The constraint also rejects duplicates introduced between the check and DDL.
3. `2026_11_09_000003_add_last_activity_at_to_enquiry_workflows.php`: safely adds
   the nullable timestamp if absent; model supports filling/casting it.

The read-only preimplementation check on 9 November 2026 found no duplicate
non-null conversation IDs. Recheck during deployment; do not assume that result
remains current. No live migrations or data backfills were run during development.
Existing client assignments and audit history remain intact. SQLite adds the
nullable staff foreign key inline to avoid table-rebuild cascades into audit rows. Do not copy numeric
`assigned_to` values into `assigned_staff_id`: they identify different user tables.
`assigned_to` / `assignedUser()` are **legacy client-user assignment** only.
New internal assignments use `assigned_staff_id` / `assignedStaff()` and
`StaffUser::assignedEnquiries()`. No assignment endpoint or notifications are built.

## Creation and locking

One operational enquiry per non-null AI conversation; separate legal matters
require separate conversations. NULL-linked legacy/direct workflow records remain
valid. Direct POST and Victoria workflow starts use `EnquiryWorkflowService`,
which shares `EnquiryCreationService` with ordinary qualification. Creation locks
the conversation before lookup, uses `firstOrCreate` with Laravel's savepoint and
unique-conflict recovery, and is backed by the unique database constraint.
Callers hold the transaction through dependent updates/workflow creation. Repeated
starts return the existing workflow, including completed workflows. A start for
a conflicting practice area/workflow returns validation errors. Conversation
ownership is validated on direct POST, as it already was on chat requests.

## Classification and qualification

`PracticeArea` defines `property_law`, `family_law`, `wills_and_probate`,
`unclassified`; unknown values map to unclassified. `config/enquiries.php` owns
canonical matter mappings and aliases:

| Matter | Practice area | Example aliases |
| --- | --- | --- |
| buying_a_property | property_law | property_purchase, buying_property |
| selling_a_property | property_law | property_sale, selling_property |
| remortgage | property_law | remortgaging |
| divorce_and_separation | family_law | divorce, separation |
| making_a_will | wills_and_probate | will, making_will, will_enquiry_v1 |
| probate | wills_and_probate | estate_administration, grant_of_probate |

Ordinary chat calls `EnquiryQualificationService` after memory refresh and
consultation evaluation. It returns a typed `QualificationResult`. Casual and
incomplete requests return a decision without creating an enquiry. Missing contact
data on an existing qualified record revokes readiness by setting requires_review,
while retaining historical qualification/submission timestamps.
Persistence requires all of:

- Consultation recommended, plus legal-matter evidence (recognised area/type or
  recognised legal intent).
- Explicit consultation/review/escalation reason, **or** a configured legal intent
  meeting `intent_confidence_threshold` (initially 0.80).
- Nonblank account name and at least one valid contact channel: valid email or
  a contact number containing 7–15 digits.

Automatic qualification/submission additionally requires a canonical practice
area and compatible recognised matter type, with classification confidence at
least `classification_confidence_threshold` (initially 0.80). Unknown,
conflicting or low-confidence classifications persist as `unclassified`, null
matter type, `requires_review = true`, without qualification/submission timestamps.
The confidence is stored on a 0–1 scale. Stage `completed` alone is never used.
Staff corrections (`classification_source = staff`) are authoritative and are
preserved on later refreshes; they must still identify a valid area/type and have
contact data before readiness. This phase adds no staff correction API.

Ordinary enquiries use `workflow_key = victoria_conversation`, without creating
an `enquiry_workflows` row. They remain readable through the existing enquiry GET.
Qualification creates three audit events once: `enquiry_qualified`,
`enquiry_submitted`, `enquiry_ready_for_assignment` (last only if unassigned).
Review transitions record `classification_requires_review`, including the reason.
All use `enquiry_events`, within the same transaction. No notifications are sent.

## Will completion and population

Will completion updates its existing enquiry inside `submitAnswer`'s locked
transaction. Deterministic Will workflow completion establishes wills_and_probate /
making_a_will with source `workflow` and confidence 1, overriding stale AI memory
unless staff already corrected the classification. Current account name, email
and **contact_number** populate client fields. Office/service come from current
conversation/request metadata. Memory summary is copied as JSON `{"text": "..."}`;
existing summary is preserved if no new memory summary exists. Office/service
and summary are optional; contact and classification govern readiness.

A completed questionnaire may still require review for missing contact data.
`completed_at` describes questionnaire completion; `qualified_at` and `submitted_at`
describe operational handoff. Enquiries remain unassigned until staff assignment;
automatic updates do not clear an existing staff assignment. `requires_review`
can subsequently become true without erasing historical submission timestamps.
Readiness therefore requires valid classification/contact, no review requirement,
qualification/submission timestamps and no assigned staff, not just `completed`.
Conversation status and AI stage do not establish handoff.

## Validation and known follow-up

Tests use SQLite in memory plus a separate disposable file DB for two actual
creator processes. The concurrency fixture explicitly reserves the SQLite writer lock before
reads to emulate PostgreSQL conversation locking; it does not validate PostgreSQL-specific row-lock scheduling.
No test writes to the configured staging/production database.

Run `php artisan test --filter=Enquiry`, `php artisan test --filter=Staff`, and
`php artisan test`.

Answer revisions are unchanged: the service increments revision, but the existing
unique `(workflow_id, question_key)` permits only one stored row per question.
Multiple answer revisions require a deliberate later schema/behaviour decision.

Validated on 9 November 2026:

| Command | Result |
| --- | --- |
| `php artisan test --filter=Enquiry` | 31 passed, 155 assertions |
| `php artisan test --filter=Staff` | 80 passed, 298 assertions |
| `php artisan test` | 125 passed, 460 assertions |

The Staff filter also matches two new enquiry tests concerning staff assignment
and staff classification. Changed PHP files were formatted with Pint, and
`git diff --check` passed.
