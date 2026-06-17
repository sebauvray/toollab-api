<?php

namespace App\Notifications;

use App\Models\Family;
use App\Models\Role;
use App\Models\SchoolYear;
use App\Models\StudentClassroom;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PaymentCompletedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public $tries = 3;
    public $backoff = [60, 300, 900];

    private $family;
    private $paymentDetails;
    private ?int $schoolId = null;
    private ?int $schoolYearId = null;

    public function __construct(Family $family, array $paymentDetails, ?int $schoolYearId = null)
    {
        $this->family = $family;
        $this->paymentDetails = $paymentDetails;
        $this->schoolId = $family->school_id;
        $this->schoolYearId = $schoolYearId;
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $previous = [
            request()->attributes->get('current_school_id'),
            request()->attributes->get('current_school_year_id'),
        ];

        $schoolId = $this->schoolId ?? $this->family->school_id;
        $schoolYearId = $this->schoolYearId ?? $this->activeSchoolYearId($schoolId);

        if ($schoolId) {
            request()->attributes->set('current_school_id', $schoolId);
        }

        if ($schoolYearId) {
            request()->attributes->set('current_school_year_id', $schoolYearId);
        }

        try {
            return $this->buildMail($notifiable, $schoolId, $schoolYearId);
        } finally {
            request()->attributes->set('current_school_id', $previous[0]);
            request()->attributes->set('current_school_year_id', $previous[1]);
        }
    }

    private function buildMail(object $notifiable, ?int $schoolId, ?int $schoolYearId): MailMessage
    {
        $students = $this->familyStudents();

        $studentsData = $students->map(function ($student) use ($schoolYearId) {
            $enrollments = StudentClassroom::query()
                ->withoutGlobalScopes()
                ->where('status', 'active')
                ->where('student_id', $student->id)
                ->when($schoolYearId, fn ($query) => $query->where('school_year_id', $schoolYearId))
                ->with([
                    'classroom' => fn ($query) => $query->withoutGlobalScopes(),
                    'classroom.schedules.teacher',
                    'classroom.cursus' => fn ($query) => $query->withoutGlobalScopes(),
                    'classroom.school',
                ])
                ->get();

            return [
                'student' => $student,
                'enrollments' => $enrollments
            ];
        });

        $responsibles = $this->familyResponsibles();
        $familyName = 'Famille';

        if ($responsibles->isNotEmpty()) {
            $firstResponsible = $responsibles->first();
            $familyName = $firstResponsible->first_name . ' ' . $firstResponsible->last_name;
        }

        $schoolName = null;
        if ($studentsData->isNotEmpty()) {
            $firstEnrollment = $studentsData->first()['enrollments']->first();
            if ($firstEnrollment && $firstEnrollment->classroom && $firstEnrollment->classroom->school) {
                $schoolName = $firstEnrollment->classroom->school->name;
            }
        }

        $schoolYearLabel = null;
        if ($schoolYearId) {
            $schoolYearLabel = SchoolYear::query()
                ->withoutGlobalScopes()
                ->when($schoolId, fn ($query) => $query->where('school_id', $schoolId))
                ->where('id', $schoolYearId)
                ->value('label');
        }

        return (new MailMessage)
            ->subject('Confirmation d\'inscription')
            ->view('emails.payment-completed', [
                'family' => $this->family,
                'familyName' => $familyName,
                'schoolName' => $schoolName,
                'schoolYearLabel' => $schoolYearLabel,
                'studentsData' => $studentsData,
                'paymentDetails' => $this->paymentDetails,
                'notifiable' => $notifiable
            ]);
    }

    private function familyStudents()
    {
        $studentRoleId = Role::query()->where('slug', 'student')->value('id');

        return User::query()
            ->whereHas('roles', function ($query) use ($studentRoleId) {
                $query->where('roleable_type', 'family')
                    ->where('roleable_id', $this->family->id)
                    ->when($studentRoleId, fn ($q) => $q->where('role_id', $studentRoleId));
            })
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get();
    }

    private function familyResponsibles()
    {
        $responsibleRoleId = Role::query()->where('slug', 'responsible')->value('id');

        return User::query()
            ->whereHas('roles', function ($query) use ($responsibleRoleId) {
                $query->where('roleable_type', 'family')
                    ->where('roleable_id', $this->family->id)
                    ->when($responsibleRoleId, fn ($q) => $q->where('role_id', $responsibleRoleId));
            })
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get();
    }

    private function activeSchoolYearId(?int $schoolId): ?int
    {
        if (! $schoolId) {
            return null;
        }

        return SchoolYear::query()
            ->withoutGlobalScopes()
            ->where('school_id', $schoolId)
            ->where('is_active', true)
            ->value('id');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'family_id' => $this->family->id,
            'payment_completed' => true,
        ];
    }
}
