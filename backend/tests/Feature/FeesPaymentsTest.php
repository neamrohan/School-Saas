<?php

namespace Tests\Feature;

use App\Models\FeeType;
use App\Models\Payment;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentFee;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

class FeesPaymentsTest extends TestCase
{
    use DatabaseTransactions;

    public function test_school_admin_can_create_a_fee_type(): void
    {
        $scenario = $this->scenario();

        $this->actingAs($scenario['admin'], 'sanctum')
            ->postJson('/api/fee-types', ['name' => 'Tuition', 'amount' => '100.00'])
            ->assertCreated()
            ->assertJsonPath('message', 'Fee type created successfully.');
    }

    public function test_duplicate_fee_type_names_are_rejected_within_a_school(): void
    {
        $scenario = $this->scenario();
        FeeType::create(['school_id' => $scenario['school']->id, 'name' => 'Tuition', 'amount' => '100.00']);

        $this->actingAs($scenario['admin'], 'sanctum')
            ->postJson('/api/fee-types', ['name' => 'Tuition', 'amount' => '100.00'])
            ->assertStatus(422);
    }

    public function test_same_fee_type_name_can_exist_in_different_schools(): void
    {
        $first = $this->scenario();
        $second = $this->scenario();

        $this->actingAs($first['admin'], 'sanctum')->postJson('/api/fee-types', ['name' => 'Tuition', 'amount' => '100.00'])->assertCreated();
        $this->actingAs($second['admin'], 'sanctum')->postJson('/api/fee-types', ['name' => 'Tuition', 'amount' => '100.00'])->assertCreated();
    }

    public function test_student_fee_can_be_assigned_to_valid_student(): void
    {
        $scenario = $this->scenario();
        $feeType = $this->feeType($scenario);

        $this->actingAs($scenario['admin'], 'sanctum')
            ->postJson('/api/student-fees', [
                'student_id' => $scenario['student']->id,
                'fee_type_id' => $feeType->id,
                'amount' => '100.00',
            ])
            ->assertCreated()
            ->assertJsonPath('message', 'Student fee created successfully.');
    }

    public function test_cross_school_student_fee_access_is_rejected(): void
    {
        $first = $this->scenario();
        $second = $this->scenario();
        $fee = StudentFee::create([
            'school_id' => $second['school']->id,
            'student_id' => $second['student']->id,
            'fee_type_id' => $this->feeType($second)->id,
            'amount' => '100.00',
            'status' => 'unpaid',
        ]);

        $this->actingAs($first['admin'], 'sanctum')
            ->getJson('/api/student-fees/'.$fee->id)
            ->assertStatus(403)
            ->assertJsonPath('message', 'Unauthorized school access.');
    }

    public function test_payment_can_be_recorded_and_partial_status_is_set(): void
    {
        $scenario = $this->scenario();
        $fee = $this->studentFee($scenario, '100.00');

        $this->actingAs($scenario['admin'], 'sanctum')
            ->postJson('/api/payments', $this->paymentPayload($scenario, $fee, '40.00'))
            ->assertCreated()
            ->assertJsonPath('message', 'Payment recorded successfully.');

        $this->assertDatabaseHas('student_fees', ['id' => $fee->id, 'status' => 'partial']);
    }

    public function test_payment_cannot_exceed_remaining_due(): void
    {
        $scenario = $this->scenario();
        $fee = $this->studentFee($scenario, '100.00');

        $this->actingAs($scenario['admin'], 'sanctum')
            ->postJson('/api/payments', $this->paymentPayload($scenario, $fee, '100.01'))
            ->assertStatus(422)
            ->assertJsonPath('message', 'Payment exceeds the remaining due amount.');
    }

    public function test_full_payment_sets_paid_status(): void
    {
        $scenario = $this->scenario();
        $fee = $this->studentFee($scenario, '100.00');

        $this->actingAs($scenario['admin'], 'sanctum')
            ->postJson('/api/payments', $this->paymentPayload($scenario, $fee, '100.00'))
            ->assertCreated();

        $this->assertDatabaseHas('student_fees', ['id' => $fee->id, 'status' => 'paid']);
    }

    public function test_summary_returns_correct_totals_and_payment_history(): void
    {
        $scenario = $this->scenario();
        $fee = $this->studentFee($scenario, '100.00');
        Payment::create([
            ...$this->paymentPayload($scenario, $fee, '40.00'),
            'school_id' => $scenario['school']->id,
        ]);

        $this->actingAs($scenario['admin'], 'sanctum')
            ->getJson('/api/students/'.$scenario['student']->id.'/fees/summary')
            ->assertOk()
            ->assertJsonPath('total_fees', '100.00')
            ->assertJsonPath('total_paid', '40.00')
            ->assertJsonPath('total_due', '60.00')
            ->assertJsonCount(1, 'payments');
    }

    public function test_cross_school_payment_access_is_rejected(): void
    {
        $first = $this->scenario();
        $second = $this->scenario();
        $fee = $this->studentFee($second, '100.00');
        $payment = Payment::create([
            ...$this->paymentPayload($second, $fee, '20.00'),
            'school_id' => $second['school']->id,
        ]);

        $this->actingAs($first['admin'], 'sanctum')
            ->getJson('/api/payments/'.$payment->id)
            ->assertStatus(403)
            ->assertJsonPath('message', 'Unauthorized school access.');
    }

    private function scenario(): array
    {
        $school = School::create(['name' => 'Fees School '.Str::random(8), 'code' => 'FEE-'.Str::upper(Str::random(8))]);
        $admin = User::factory()->create(['school_id' => $school->id, 'role' => 'school_admin']);
        $studentUser = User::factory()->create(['school_id' => $school->id, 'role' => 'student']);
        $student = Student::create(['user_id' => $studentUser->id, 'school_id' => $school->id]);
        return compact('school', 'admin', 'student');
    }

    private function feeType(array $scenario): FeeType
    {
        return FeeType::create(['school_id' => $scenario['school']->id, 'name' => 'Tuition '.Str::random(6), 'amount' => '100.00']);
    }

    private function studentFee(array $scenario, string $amount): StudentFee
    {
        return StudentFee::create(['school_id' => $scenario['school']->id, 'student_id' => $scenario['student']->id, 'fee_type_id' => $this->feeType($scenario)->id, 'amount' => $amount, 'status' => 'unpaid']);
    }

    private function paymentPayload(array $scenario, StudentFee $fee, string $amount): array
    {
        return ['student_fee_id' => $fee->id, 'student_id' => $scenario['student']->id, 'amount' => $amount, 'payment_date' => '2026-09-21', 'payment_method' => 'cash'];
    }
}