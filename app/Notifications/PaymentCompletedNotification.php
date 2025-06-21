<?php

namespace App\Notifications;

use App\Models\Family;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PaymentCompletedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    private $family;
    private $paymentDetails;

    public function __construct(Family $family, array $paymentDetails)
    {
        $this->family = $family;
        $this->paymentDetails = $paymentDetails;
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $students = $this->family->students()->with(['studentClassrooms.classroom.schedules', 'studentClassrooms.classroom.school'])->get();

        $studentsData = $students->map(function ($student) {
            $enrollments = $student->studentClassrooms()
                ->where('status', 'active')
                ->with(['classroom.schedules', 'classroom.cursus', 'classroom.school'])
                ->get();

            return [
                'student' => $student,
                'enrollments' => $enrollments
            ];
        });

        $responsibles = $this->family->responsibles()->get();
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

        return (new MailMessage)
            ->subject('Confirmation d\'inscription - ' . $familyName)
            ->view('emails.payment-completed', [
                'family' => $this->family,
                'familyName' => $familyName,
                'schoolName' => $schoolName,
                'studentsData' => $studentsData,
                'paymentDetails' => $this->paymentDetails,
                'notifiable' => $notifiable
            ]);
    }

    public function toArray(object $notifiable): array
    {
        return [
            'family_id' => $this->family->id,
            'payment_completed' => true,
        ];
    }
}
