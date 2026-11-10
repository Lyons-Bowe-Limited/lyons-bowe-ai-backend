<?php

namespace Tests\Feature;

use App\Models\Enquiry;
use App\Models\StaffRole;
use App\Models\StaffUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class StaffMyWorkSortingTest extends TestCase
{
    use RefreshDatabase;

    private function staff(): StaffUser
    {
        $staff = StaffUser::create(['first_name' => 'Sort', 'last_name' => 'User', 'email' => Str::uuid().'@example.com', 'status' => 'active', 'email_verified_at' => now()]);
        $staff->roles()->attach(StaffRole::where('name', 'solicitor')->sole());

        return $staff;
    }

    private function enquiry(?StaffUser $owner, ?string $priority = 'normal', string $activity = '2026-11-10 08:00:00'): Enquiry
    {
        return Enquiry::create([
            'reference' => 'SORT-'.Str::uuid(), 'workflow_key' => 'victoria_conversation', 'practice_area' => 'family_law',
            'status' => 'submitted', 'client_name' => 'Sort Client', 'client_email' => 'sort@example.com',
            'assigned_staff_id' => $owner?->id, 'priority' => $priority, 'last_activity_at' => $activity,
        ]);
    }

    public function test_priority_first_is_semantic_with_legacy_low_and_null_unknown_last(): void
    {
        // Exercise nullable historical data without changing production schema.
        Schema::table('enquiries', fn (Blueprint $table) => $table->string('priority')->nullable()->change());
        $owner = $this->staff();
        $normal = $this->enquiry($owner, 'normal', '2026-11-10 12:00:00');
        $low = $this->enquiry($owner, 'low', '2026-11-10 12:00:00');
        $urgent = $this->enquiry($owner, 'urgent', '2026-11-10 01:00:00');
        $unknown = $this->enquiry($owner, 'legacy_unknown', '2026-11-10 12:00:00');
        $high = $this->enquiry($owner, 'high', '2026-11-10 02:00:00');
        $null = $this->enquiry($owner, null, '2026-11-10 13:00:00');
        $expected = [$urgent->id, $high->id, $normal->id, $low->id, $null->id, $unknown->id];
        $this->actingAs($owner, 'staff_sanctum');
        // Priority-first always follows semantic rank, irrespective of direction.
        foreach (['', '&direction=desc', '&direction=asc'] as $direction) {
            $response = $this->getJson('/api/staff/my-work/enquiries?sort=priority'.$direction)->assertOk();
            $this->assertSame($expected, $response->json('data.*.id'));
        }
    }

    public function test_priority_ties_use_activity_descending_then_id_descending_deterministically(): void
    {
        $owner = $this->staff();
        $older = $this->enquiry($owner, 'urgent', '2026-11-10 08:00:00');
        $first = $this->enquiry($owner, 'urgent', '2026-11-10 09:00:00');
        $second = $this->enquiry($owner, 'urgent', '2026-11-10 09:00:00');
        $ties = collect([$first->id, $second->id])->sortDesc()->values()->all();
        $expected = [...$ties, $older->id];
        $this->actingAs($owner, 'staff_sanctum');
        foreach ([1, 2] as $_) {
            $this->assertSame($expected, $this->getJson('/api/staff/my-work/enquiries?sort=priority')->assertOk()->json('data.*.id'));
        }
        foreach ($expected as $index => $id) {
            $this->getJson('/api/staff/my-work/enquiries?sort=priority&per_page=1&page='.($index + 1))->assertOk()->assertJsonPath('data.0.id', $id)->assertJsonPath('meta.total', 3);
        }
    }

    public function test_all_allowed_date_sorts_honour_direction_and_only_include_authenticated_assignments(): void
    {
        $owner = $this->staff();
        $other = $this->staff();
        $older = $this->enquiry($owner);
        $newer = $this->enquiry($owner);
        $foreign = $this->enquiry($other, 'urgent');
        $unassigned = $this->enquiry(null, 'urgent');
        $older->forceFill(['created_at' => '2026-11-09 08:00:00', 'submitted_at' => '2026-11-09 08:00:00', 'last_activity_at' => '2026-11-09 08:00:00'])->save();
        $newer->forceFill(['created_at' => '2026-11-10 08:00:00', 'submitted_at' => '2026-11-10 08:00:00', 'last_activity_at' => '2026-11-10 08:00:00'])->save();
        $this->actingAs($owner, 'staff_sanctum');
        foreach (['created_at', 'last_activity_at', 'submitted_at'] as $sort) {
            foreach (['asc' => [$older->id, $newer->id], 'desc' => [$newer->id, $older->id]] as $direction => $expected) {
                $response = $this->getJson('/api/staff/my-work/enquiries?sort='.$sort.'&direction='.$direction)->assertOk()->assertJsonPath('meta.total', 2);
                $this->assertSame($expected, $response->json('data.*.id'));
                $this->assertNotContains($foreign->id, $response->json('data.*.id'));
                $this->assertNotContains($unassigned->id, $response->json('data.*.id'));
            }
        }
        $this->getJson('/api/staff/my-work/enquiries')->assertOk()->assertJsonPath('data.0.id', $newer->id);
    }

    public function test_priority_sort_and_scope_escape_attempts_never_return_another_staff_members_work(): void
    {
        $owner = $this->staff();
        $other = $this->staff();
        $own = $this->enquiry($owner, 'normal');
        $this->enquiry($other, 'urgent');
        $this->enquiry(null, 'urgent');
        $this->actingAs($owner, 'staff_sanctum');
        $this->getJson('/api/staff/my-work/enquiries?sort=priority')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $own->id)->assertJsonPath('meta.total', 1);
        $this->getJson('/api/staff/my-work/enquiries?sort=priority&assigned_staff_id='.$other->id)->assertUnprocessable();
        $this->getJson('/api/staff/my-work/enquiries?sort=priority&scope=all&filter[assigned_staff_id]='.$other->id)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $own->id);
    }

    public function test_invalid_sorts_and_sql_expressions_return_422(): void
    {
        $owner = $this->staff();
        $this->enquiry($owner);
        $this->actingAs($owner, 'staff_sanctum');
        foreach (['reference', 'id', 'assigned_staff_id', 'priority desc', 'CASE WHEN priority = urgent THEN 0 END', 'created_at; DROP TABLE enquiries', 'unknown'] as $sort) {
            $this->getJson('/api/staff/my-work/enquiries?'.http_build_query(['sort' => $sort]))->assertUnprocessable()->assertJsonValidationErrors('sort');
        }
        $this->getJson('/api/staff/my-work/enquiries?sort=')->assertUnprocessable()->assertJsonValidationErrors('sort');
        $this->getJson('/api/staff/my-work/enquiries?sort[]=priority')->assertUnprocessable()->assertJsonValidationErrors('sort');
        $this->assertDatabaseCount('enquiries', 1);
    }
}
