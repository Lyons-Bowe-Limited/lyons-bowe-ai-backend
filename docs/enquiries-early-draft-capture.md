# Early draft enquiry capture

This extends the Phase 1/3 creation lifecycle. It separates early capture from
full qualification; the existing conversation/enquiry uniqueness constraint,
creation service and row locks still enforce one enquiry per conversation.
No migration, frontend, notification or staff permission change is required.

## Early capture

An ordinary authenticated Victoria conversation can create a draft when:

- Its supported practice area (property_law, family_law, wills_and_probate) meets
  enquiries.classification_confidence_threshold (currently 0.80).
- There is a clear legal-help/instruction request, identified by a configured
  structured legal intent meeting intent_confidence_threshold (currently 0.80),
  or a direct client help/assistance/instruction statement in the latest message.
- The linked authenticated account has a nonblank name and valid email address.

The new direct-request detector covers statements such as “I need help with family
arrangements”, “Can you help me”, and “I want to instruct a solicitor”. It excludes
informational requests to understand/learn about a topic. Claude's extraction
prompt now explicitly distinguishes personal legal-help intent from general
information and retains a known practice area when the specific matter is unknown.

Capture does not depend on consultation recommendation, conversation stage,
questionnaire completion, specific matter type or full submission facts. A missing,
unrecognised or incompatible matter type is stored as null while a confidently
identified practice area is retained. Insufficient practice-area confidence still
requires the existing unclassified/review treatment; those thresholds are unchanged.

Initial early records have status=draft, assigned_staff_id=null,
handoff_reason=information_gathering and no qualified_at/submitted_at. They remain
under review while qualification is incomplete, including an unclear matter type.
Current merged account/contact data and available structured summary populate the
record. Existing priority mapping runs immediately, with all Phase 3 staff-override
and no-automatic-lowering safeguards retained.

The exact staging statement “This is a matter of urgency now” is also recognised
as a direct urgency assertion. This is structured as explicit_urgent rather than
inferred from summary text. Casual informational Family Law questions create no
record. Direct-request fallbacks are intentionally narrow; broader language still
uses structured classification/intent extraction.

## Progressive updates and submission

Existing drafts refresh progressively, including short clarifications that do
not repeat the original legal-help request. Matter type, summary, contacts and
review information update on the same enquiry ID/reference. Priority may rise
as new urgency is detected. Staff classification and priority overrides remain
protected.

A canonical matching matter type alone does not submit a draft. Full qualification
still requires supported/confident classification, sufficient legal intent,
consultation recommendation, complete merged contact data and applicable staff
review safeguards. Only then does status move draft -> submitted and the service
set qualified_at/submitted_at and emit qualification/submission events. Stage
labels alone do not establish readiness. Repeated updates do not duplicate the
record, submission timestamps or submission events.

Existing deterministic Will workflow start/answer/completion handling is unchanged.
The prior full-handoff review path for uncertain classifications is also retained.
This change adds an earlier capture path; it does not remove existing enquiries
or reinterpret historical submission/qualification timestamps.

The staff list already includes drafts; global all/unassigned/review counts can
include them. The submitted count still requires status=submitted. Staff API
classification correction payloads are unchanged; information_gathering is an
automatically generated handoff reason.

## Changed files

- app/Services/Enquiries/EnquiryQualificationService.php
- app/Services/Enquiries/LegalHelpIntentDetector.php
- app/Services/Enquiries/UrgencyDetectionService.php
- app/Services/ClaudeService.php
- tests/Feature/EnquiryDraftCaptureTest.php
- tests/Feature/EnquiryQualificationTest.php (updated obsolete matter-type expectations)
- docs/enquiries-early-draft-capture.md


## Validation

`php artisan test --filter=Enquiry` passed: 105 tests, 744 assertions. This includes
nine early draft capture regressions plus the existing qualification, priority,
idempotency and staff API tests. The full suite passed: 199 tests, 1,049 assertions.
Pint formatting checks and `git diff --check` passed. No staging data was changed.
