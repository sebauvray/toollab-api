<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Family;
use App\Models\Classroom;
use App\Models\StudentClassroom;
use App\Models\Paiement;
use App\Models\LignePaiement;
use App\Models\Cursus;
use App\Models\Tarif;
use App\Models\School;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class StatisticsController extends Controller
{
    public function overview(Request $request)
    {
        $user = $request->user();
        $schoolId = $request->input('school_id', session('current_school_id'));
        
        $school = School::find($schoolId);
        if (!$school) {
            return response()->json(['error' => 'École non trouvée'], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'enrollments' => $this->getEnrollmentStats($schoolId),
                'payments' => $this->getPaymentStats($schoolId),
                'classes' => $this->getClassStats($schoolId),
                'families' => $this->getFamilyStats($schoolId),
            ]
        ]);
    }

    private function getEnrollmentStats($schoolId)
    {
        // Get all students from families in this school
        $students = User::whereHas('roles', function ($query) use ($schoolId) {
            $query->where('roleable_type', 'family')
                ->whereHas('role', function ($q) {
                    $q->where('slug', 'student');
                })
                ->whereIn('roleable_id', Family::where('school_id', $schoolId)->pluck('id'));
        })
        ->with(['infos' => function ($query) {
            $query->whereIn('key', ['gender', 'birthdate']);
        }])
        ->get();

        $stats = [
            'total' => $students->count(),
            'men' => 0,
            'women' => 0,
            'children' => 0,
        ];

        foreach ($students as $student) {
            $gender = $student->infos->where('key', 'gender')->first()?->value;
            $birthdate = $student->infos->where('key', 'birthdate')->first()?->value;
            
            if ($birthdate) {
                $age = Carbon::parse($birthdate)->age;
                if ($age < 16) {
                    $stats['children']++;
                } else {
                    if ($gender === 'M') {
                        $stats['men']++;
                    } else {
                        $stats['women']++;
                    }
                }
            }
        }

        return $stats;
    }

    private function getPaymentStats($schoolId)
    {
        $families = Family::where('school_id', $schoolId)->pluck('id');
        
        $payments = LignePaiement::whereHas('paiement', function ($query) use ($families) {
            $query->whereIn('family_id', $families);
        })->get();

        $stats = [
            'total_amount' => round($payments->sum('montant')),
            'by_type' => [
                'cheque' => [
                    'amount' => round($payments->where('type_paiement', 'cheque')->sum('montant')),
                    'count' => $payments->where('type_paiement', 'cheque')->count(),
                ],
                'espece' => [
                    'amount' => round($payments->where('type_paiement', 'espece')->sum('montant')),
                    'count' => $payments->where('type_paiement', 'espece')->count(),
                ],
                'carte' => [
                    'amount' => round($payments->where('type_paiement', 'carte')->sum('montant')),
                    'count' => $payments->where('type_paiement', 'carte')->count(),
                ],
                'exoneration' => [
                    'amount' => round($payments->where('type_paiement', 'exoneration')->sum('montant')),
                    'count' => $payments->where('type_paiement', 'exoneration')->count(),
                ],
            ],
        ];

        $expectedRevenue = $this->calculateExpectedRevenue($schoolId);
        $stats['remaining'] = round($expectedRevenue - $stats['total_amount']);
        $stats['expected'] = round($expectedRevenue);
        $stats['payment_rate'] = $expectedRevenue > 0 ? round(($stats['total_amount'] / $expectedRevenue) * 100, 2) : 0;

        return $stats;
    }

    private function calculateExpectedRevenue($schoolId)
    {
        $enrollments = StudentClassroom::whereHas('classroom', function ($query) use ($schoolId) {
            $query->where('school_id', $schoolId);
        })
        ->where('status', 'active')
        ->with('classroom')
        ->get();

        $totalExpected = 0;
        $familyTotals = [];

        foreach ($enrollments as $enrollment) {
            $familyId = $enrollment->family_id;
            $cursusId = $enrollment->classroom->cursus_id;
            
            if (!isset($familyTotals[$familyId])) {
                $familyTotals[$familyId] = [];
            }
            
            if (!isset($familyTotals[$familyId][$cursusId])) {
                $familyTotals[$familyId][$cursusId] = 0;
            }
            
            $familyTotals[$familyId][$cursusId]++;
        }

        foreach ($familyTotals as $familyId => $cursusCounts) {
            foreach ($cursusCounts as $cursusId => $count) {
                $tarif = Tarif::where('cursus_id', $cursusId)->where('actif', true)->first();
                if ($tarif) {
                    $basePrice = $tarif->prix;
                    
                    if ($count >= 5) {
                        $totalExpected += 210 * $count;
                    } elseif ($count >= 3) {
                        $totalExpected += 240 * $count;
                    } else {
                        $totalExpected += $basePrice * $count;
                    }
                }
            }
        }

        return $totalExpected;
    }

    private function getClassStats($schoolId)
    {
        $classrooms = Classroom::where('school_id', $schoolId)->get();
        
        foreach ($classrooms as $classroom) {
            $activeStudentsCount = StudentClassroom::where('classroom_id', $classroom->id)
                ->where('status', 'active')
                ->count();
            $classroom->students_count = $activeStudentsCount;
        }

        $stats = [
            'total' => $classrooms->count(),
            'complete' => 0,
            'partial' => 0,
            'empty' => 0,
            'by_type' => [
                'Enfants' => 0,
                'Femmes' => 0,
                'Hommes' => 0,
            ],
        ];

        foreach ($classrooms as $classroom) {
            $fillRate = $classroom->size > 0 ? ($classroom->students_count / $classroom->size) : 0;
            
            if ($fillRate >= 1) {
                $stats['complete']++;
            } elseif ($fillRate > 0) {
                $stats['partial']++;
            } else {
                $stats['empty']++;
            }

            if (isset($stats['by_type'][$classroom->type])) {
                $stats['by_type'][$classroom->type]++;
            }
        }

        return $stats;
    }

    private function getFamilyStats($schoolId)
    {
        $families = Family::where('school_id', $schoolId)->get();

        $unpaidFamilies = [];
        $partiallyPaidFamilies = [];
        $paidFamilies = [];
        $totalWithStudents = 0;

        foreach ($families as $family) {
            // Count students for this family
            $studentCount = $family->students()->count();
            if ($studentCount > 0) {
                $totalWithStudents++;
            }

            $expectedAmount = $this->calculateFamilyExpectedAmount($family);
            $paidAmount = $this->getFamilyPaidAmount($family->id);
            
            if ($expectedAmount > 0) {
                if ($paidAmount == 0) {
                    $unpaidFamilies[] = [
                        'id' => $family->id,
                        'expected' => round($expectedAmount),
                        'paid' => 0,
                    ];
                } elseif ($paidAmount < $expectedAmount) {
                    $partiallyPaidFamilies[] = [
                        'id' => $family->id,
                        'expected' => round($expectedAmount),
                        'paid' => round($paidAmount),
                        'remaining' => round($expectedAmount - $paidAmount),
                    ];
                } elseif ($paidAmount >= $expectedAmount) {
                    $paidFamilies[] = [
                        'id' => $family->id,
                        'expected' => round($expectedAmount),
                        'paid' => round($paidAmount),
                    ];
                }
            }
        }

        return [
            'total' => $families->count(),
            'with_students' => $totalWithStudents,
            'paid_count' => count($paidFamilies),
            'unpaid_count' => count($unpaidFamilies),
            'partially_paid_count' => count($partiallyPaidFamilies),
            'unpaid_families' => $unpaidFamilies,
            'partially_paid_families' => $partiallyPaidFamilies,
            'paid_families' => $paidFamilies,
        ];
    }

    private function calculateFamilyExpectedAmount($family)
    {
        $enrollments = StudentClassroom::where('family_id', $family->id)
            ->where('status', 'active')
            ->with('classroom')
            ->get();

        $cursusCounts = [];
        foreach ($enrollments as $enrollment) {
            if ($enrollment->classroom) {
                $cursusId = $enrollment->classroom->cursus_id;
                if (!isset($cursusCounts[$cursusId])) {
                    $cursusCounts[$cursusId] = 0;
                }
                $cursusCounts[$cursusId]++;
            }
        }

        $total = 0;
        foreach ($cursusCounts as $cursusId => $count) {
            $tarif = Tarif::where('cursus_id', $cursusId)->where('actif', true)->first();
            if ($tarif) {
                if ($count >= 5) {
                    $total += 210 * $count;
                } elseif ($count >= 3) {
                    $total += 240 * $count;
                } else {
                    $total += $tarif->prix * $count;
                }
            }
        }

        return $total;
    }

    private function getFamilyPaidAmount($familyId)
    {
        $paiement = Paiement::where('family_id', $familyId)->first();
        if (!$paiement) {
            return 0;
        }

        return LignePaiement::where('paiement_id', $paiement->id)->sum('montant');
    }

    public function unpaidFamilies(Request $request)
    {
        $schoolId = $request->input('school_id', session('current_school_id'));
        $families = Family::where('school_id', $schoolId)->get();

        $unpaidData = [];

        foreach ($families as $family) {
            $expectedAmount = $this->calculateFamilyExpectedAmount($family);
            $paidAmount = $this->getFamilyPaidAmount($family->id);
            
            if ($paidAmount < $expectedAmount) {
                $responsibles = $family->responsibles()->get();
                $students = $family->students()->with('infos')->get();
                
                $unpaidData[] = [
                    'id' => $family->id,
                    'responsibles' => $responsibles->map(function ($r) {
                        return [
                            'id' => $r->id,
                            'name' => $r->first_name . ' ' . $r->last_name,
                            'email' => $r->email,
                        ];
                    }),
                    'students_count' => $students->count(),
                    'expected' => round($expectedAmount),
                    'paid' => round($paidAmount),
                    'remaining' => round($expectedAmount - $paidAmount),
                    'payment_rate' => $expectedAmount > 0 ? round(($paidAmount / $expectedAmount) * 100, 2) : 0,
                ];
            }
        }

        return response()->json([
            'status' => 'success',
            'data' => $unpaidData
        ]);
    }

    public function searchPayments(Request $request)
    {
        $request->validate([
            'search_type' => 'required|in:cheque_number,emitter_name,bank',
            'search_value' => 'required|string',
        ]);

        $searchType = $request->input('search_type');
        $searchValue = $request->input('search_value');
        $schoolId = $request->input('school_id', session('current_school_id'));

        $query = LignePaiement::where('type_paiement', 'cheque')
            ->whereHas('paiement.family', function ($q) use ($schoolId) {
                $q->where('school_id', $schoolId);
            })
            ->with(['paiement.family.responsibles']);

        switch ($searchType) {
            case 'cheque_number':
                $query->where('details->numero', 'like', '%' . $searchValue . '%');
                break;
            case 'emitter_name':
                $query->where('details->emetteur', 'like', '%' . $searchValue . '%');
                break;
            case 'bank':
                $query->where('details->banque', 'like', '%' . $searchValue . '%');
                break;
        }

        $results = $query->get()->map(function ($ligne) {
            $details = $ligne->details ?: [];
            return [
                'id' => $ligne->id,
                'amount' => $ligne->montant,
                'cheque_details' => [
                    'numero' => $details['numero'] ?? '',
                    'emetteur' => $details['emetteur'] ?? '',
                    'banque' => $details['banque'] ?? ''
                ],
                'payment_date' => $ligne->created_at->format('Y-m-d'),
                'family' => [
                    'id' => $ligne->paiement->family->id,
                    'responsibles' => $ligne->paiement->family->responsibles->map(function ($r) {
                        return $r->first_name . ' ' . $r->last_name;
                    }),
                ],
            ];
        });

        return response()->json([
            'status' => 'success',
            'data' => $results
        ]);
    }

    public function enrollmentTrends(Request $request)
    {
        $schoolId = $request->input('school_id', session('current_school_id'));
        $startDate = Carbon::now()->subMonths(6);
        
        $enrollments = StudentClassroom::whereHas('classroom', function ($query) use ($schoolId) {
            $query->where('school_id', $schoolId);
        })
        ->where('enrollment_date', '>=', $startDate)
        ->selectRaw('DATE_FORMAT(enrollment_date, "%Y-%m") as month, COUNT(*) as count')
        ->groupBy('month')
        ->orderBy('month')
        ->get();

        return response()->json([
            'status' => 'success',
            'data' => $enrollments
        ]);
    }

    public function revenueByMonth(Request $request)
    {
        $schoolId = $request->input('school_id', session('current_school_id'));
        $startDate = Carbon::now()->subMonths(6);
        
        $revenue = LignePaiement::whereHas('paiement.family', function ($query) use ($schoolId) {
            $query->where('school_id', $schoolId);
        })
        ->where('created_at', '>=', $startDate)
        ->selectRaw('DATE_FORMAT(created_at, "%Y-%m") as month, type_paiement, SUM(montant) as total')
        ->groupBy(['month', 'type_paiement'])
        ->orderBy('month')
        ->get();

        $formatted = [];
        foreach ($revenue as $item) {
            if (!isset($formatted[$item->month])) {
                $formatted[$item->month] = [
                    'month' => $item->month,
                    'cheque' => 0,
                    'espece' => 0,
                    'carte' => 0,
                    'exoneration' => 0,
                    'total' => 0,
                ];
            }
            $formatted[$item->month][$item->type_paiement] = $item->total;
            $formatted[$item->month]['total'] += $item->total;
        }

        return response()->json([
            'status' => 'success',
            'data' => array_values($formatted)
        ]);
    }
}