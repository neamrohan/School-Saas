<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\SchoolContactMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SchoolContactMessageController extends Controller
{
    public function storePublic(Request $request, string $schoolCode): JsonResponse
    {
        $school = School::query()
            ->where('code', $schoolCode)
            ->where('is_active', true)
            ->firstOrFail();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'message' => ['required', 'string', 'max:5000'],
        ]);

        $school->contactMessages()->create($validated);

        return response()->json(['message' => 'Your message has been sent.'], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $school = $this->authorizedSchool($request);

        return response()->json([
            'messages' => $school->contactMessages()
                ->latest()
                ->paginate(25)
                ->through(fn (SchoolContactMessage $message): array => $this->present($message)),
        ])->header('Cache-Control', 'no-store');
    }

    public function markRead(Request $request, SchoolContactMessage $message): JsonResponse
    {
        $this->assertOwnMessage($request, $message);
        $message->forceFill(['read_at' => now()])->save();

        return response()->json(['message' => 'Message marked as read.']);
    }

    public function destroy(Request $request, SchoolContactMessage $message): JsonResponse
    {
        $this->assertOwnMessage($request, $message);
        $message->delete();

        return response()->json(['message' => 'Message deleted.']);
    }

    private function authorizedSchool(Request $request): School
    {
        $user = $request->user();
        if ($user?->role === 'super_admin') {
            $schoolId = $request->route('school');
            $school = $schoolId instanceof School ? $schoolId : School::query()->find($schoolId);
            abort_unless($school instanceof School, 404, 'Choose a school to manage.');

            return $school;
        }
        abort_unless($user?->role === 'school_admin' && $user->school instanceof School, 403);

        return $user->school;
    }

    private function assertOwnMessage(Request $request, SchoolContactMessage $message): void
    {
        abort_unless($message->school_id === $this->authorizedSchool($request)->id, 404);
    }

    /**
     * @return array<string, int|string|null>
     */
    private function present(SchoolContactMessage $message): array
    {
        return [
            'id' => $message->id,
            'name' => $message->name,
            'email' => $message->email,
            'phone' => $message->phone,
            'message' => $message->message,
            'read_at' => $message->read_at?->toISOString(),
            'created_at' => $message->created_at?->toISOString(),
        ];
    }
}
