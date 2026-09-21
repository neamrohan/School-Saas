<?php

namespace App\Services;

class GradeCalculator
{
    public function calculate(float|int|string $marks, float|int|string $fullMarks): array
    {
        $fullMarks = (float) $fullMarks;
        $percentage = $fullMarks > 0
            ? ((float) $marks / $fullMarks) * 100
            : 0;

        return match (true) {
            $percentage >= 80 => ['grade' => 'A+', 'grade_point' => 5.00],
            $percentage >= 70 => ['grade' => 'A', 'grade_point' => 4.00],
            $percentage >= 60 => ['grade' => 'A-', 'grade_point' => 3.50],
            $percentage >= 50 => ['grade' => 'B', 'grade_point' => 3.00],
            $percentage >= 40 => ['grade' => 'C', 'grade_point' => 2.00],
            $percentage >= 33 => ['grade' => 'D', 'grade_point' => 1.00],
            default => ['grade' => 'F', 'grade_point' => 0.00],
        };
    }
}