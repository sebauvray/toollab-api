<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StudentYearOutcome extends Model
{
    use HasFactory;

    protected $fillable = [
        'student_id',
        'school_year_id',
        'classroom_id',
        'outcome',
        'commentaire',
        'decided_by',
        'decided_at',
    ];

    protected $casts = [
        'decided_at' => 'datetime',
    ];

    /**
     * Retire les décisions qui accompagnaient ces inscriptions.
     *
     * La décision suit l'inscription : dès qu'un élève quitte une classe sur
     * l'année courante, la décision saisie pour ce couple (élève, classe) n'a
     * plus d'objet. Sans ce nettoyage elle reste en base, invisible partout,
     * fausse le compteur de /decisions, et RESSURGIT telle quelle si l'élève
     * est réinscrit plus tard dans la même classe.
     *
     * Le modèle n'a aucun global scope : l'année et la classe sont filtrées à
     * la main. Seule l'année COURANTE est touchée — l'historique des années
     * clôturées ne bouge pas.
     */
    public static function forgetForEnrollments(array $studentIds, array $classroomIds): int
    {
        $yearId = currentSchoolYearId();

        if ($studentIds === [] || $classroomIds === [] || $yearId === null) {
            return 0;
        }

        return static::query()
            ->where('school_year_id', $yearId)
            ->whereIn('student_id', $studentIds)
            ->whereIn('classroom_id', $classroomIds)
            ->delete();
    }

    public function student()
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function classroom()
    {
        return $this->belongsTo(Classroom::class);
    }

    public function schoolYear()
    {
        return $this->belongsTo(SchoolYear::class);
    }

    public function decidedBy()
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
