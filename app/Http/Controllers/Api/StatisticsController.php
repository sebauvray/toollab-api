<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Family;
use App\Models\Classroom;
use App\Models\StudentClassroom;
use App\Models\UserRole;
use App\Models\Paiement;
use App\Models\LignePaiement;
use App\Models\Cursus;
use App\Models\Tarif;
use App\Models\School;
use App\Services\TarifCalculatorService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class StatisticsController extends Controller
{
    public function __construct(private TarifCalculatorService $tarifCalculator)
    {
    }

    public function overview(Request $request)
    {
        $schoolId = currentSchoolId();

        $school = School::find($schoolId);
        if (!$school) {
            return response()->json(['error' => 'École non trouvée'], 404);
        }

        $financials = $this->computeFamilyFinancials($schoolId);

        return response()->json([
            'status' => 'success',
            'data' => [
                'enrollments' => $this->getEnrollmentStats($schoolId),
                'payments' => $this->getPaymentStats($schoolId, $financials),
                'classes' => $this->getClassStats($schoolId),
                'families' => $this->getFamilyStats($financials),
                'cursus' => $this->getCursusStats($schoolId),
            ]
        ]);
    }

    private function computeFamilyFinancials($schoolId): array
    {
        $familyIds = StudentClassroom::whereHas('classroom', function ($query) use ($schoolId) {
            $query->where('school_id', $schoolId);
        })
            ->where('status', 'active')
            ->distinct()
            ->pluck('family_id');

        $paidByFamily = Paiement::whereIn('family_id', $familyIds)
            ->withSum('lignes', 'montant')
            ->get()
            ->mapWithKeys(fn ($paiement) => [$paiement->family_id => (int) $paiement->lignes_sum_montant]);

        $familiesWithStudents = UserRole::where('roleable_type', 'family')
            ->whereIn('roleable_id', $familyIds)
            ->whereHas('role', fn ($q) => $q->where('slug', 'student'))
            ->distinct('roleable_id')
            ->count('roleable_id');

        $families = Family::whereIn('id', $familyIds)->get();

        $byFamily = [];
        foreach ($families as $family) {
            $byFamily[$family->id] = [
                'expected' => (int) ($this->tarifCalculator->calculerTotalFamille($family)['total'] ?? 0),
                'paid' => $paidByFamily[$family->id] ?? 0,
            ];
        }

        return [
            'families' => $byFamily,
            'with_students' => $familiesWithStudents,
        ];
    }

    private function getEnrollmentStats($schoolId)
    {
        // Élèves inscrits dans une classe de l'année scolaire courante uniquement.
        // whereHas('classroom') applique le global scope BelongsToSchoolYear de Classroom.
        $studentIds = StudentClassroom::whereHas('classroom', function ($query) use ($schoolId) {
            $query->where('school_id', $schoolId);
        })
            ->where('status', 'active')
            ->distinct()
            ->pluck('student_id');

        $students = User::whereIn('id', $studentIds)
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

            $age = $birthdate ? Carbon::parse($birthdate)->age : null;

            if ($age !== null && $age < 16) {
                $stats['children']++;
            } elseif ($gender === 'M') {
                $stats['men']++;
            } else {
                $stats['women']++;
            }
        }

        return $stats;
    }

    private function getPaymentStats($schoolId, array $financials)
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

        $expectedRevenue = array_sum(array_column($financials['families'], 'expected'));
        $stats['remaining'] = round($expectedRevenue - $stats['total_amount']);
        $stats['expected'] = round($expectedRevenue);
        $stats['payment_rate'] = $expectedRevenue > 0 ? round(($stats['total_amount'] / $expectedRevenue) * 100, 2) : 0;

        return $stats;
    }

    private function getClassStats($schoolId)
    {
        $classrooms = Classroom::where('school_id', $schoolId)
            ->withCount('activeStudents')
            ->get();

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
            $fillRate = $classroom->size > 0 ? ($classroom->active_students_count / $classroom->size) : 0;

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

    private function getFamilyStats(array $financials)
    {
        $paidCount = 0;
        $partiallyPaidCount = 0;
        $fullyUnpaidCount = 0;

        foreach ($financials['families'] as $f) {
            if ($f['expected'] <= 0 || $f['paid'] >= $f['expected']) {
                $paidCount++;
            } elseif ($f['paid'] > 0) {
                $partiallyPaidCount++;
            } else {
                $fullyUnpaidCount++;
            }
        }

        return [
            'total' => count($financials['families']),
            'with_students' => $financials['with_students'],
            'paid_count' => $paidCount,
            'partially_paid_count' => $partiallyPaidCount,
            'fully_unpaid_count' => $fullyUnpaidCount,
            'unpaid_count' => $partiallyPaidCount + $fullyUnpaidCount,
        ];
    }

    private function calculateFamilyExpectedAmount($family)
    {
        return (int) ($this->tarifCalculator->calculerTotalFamille($family)['total'] ?? 0);
    }

    private function getCursusStats($schoolId)
    {
        // Récupérer tous les cursus de l'école
        $cursusStats = [];
        
        // Récupérer tous les cursus utilisés dans les classes de cette école
        $cursusIds = Classroom::where('school_id', $schoolId)
            ->whereNotNull('cursus_id')
            ->distinct()
            ->pluck('cursus_id');
        
        foreach ($cursusIds as $cursusId) {
            $cursus = Cursus::find($cursusId);
            if (!$cursus) continue;
            
            // Capacité totale : somme des capacités de toutes les classes de ce cursus
            $totalCapacity = Classroom::where('school_id', $schoolId)
                ->where('cursus_id', $cursusId)
                ->sum('size');
            
            // Nombre d'élèves inscrits : nombre d'inscriptions actives dans les classes de ce cursus
            $enrolledStudents = StudentClassroom::whereHas('classroom', function ($query) use ($schoolId, $cursusId) {
                $query->where('school_id', $schoolId)
                    ->where('cursus_id', $cursusId);
            })
            ->where('status', 'active')
            ->count();
            
            // Calcul du taux de remplissage
            $fillRate = $totalCapacity > 0 ? round(($enrolledStudents / $totalCapacity) * 100, 2) : 0;
            
            $cursusStats[] = [
                'id' => $cursusId,
                'name' => $cursus->name,
                'total_capacity' => $totalCapacity,
                'enrolled_students' => $enrolledStudents,
                'available_places' => $totalCapacity - $enrolledStudents,
                'fill_rate' => $fillRate,
            ];
        }
        
        // Trier par taux de remplissage décroissant
        usort($cursusStats, function($a, $b) {
            return $b['fill_rate'] <=> $a['fill_rate'];
        });
        
        return $cursusStats;
    }

    public function unpaidFamilies(Request $request)
    {
        $schoolId = currentSchoolId();
        $page = $request->input('page', 1);
        $perPage = min((int) $request->input('per_page', 10), 100);
        $search = $request->input('search', '');
        $filter = $request->input('filter', ''); // 'unpaid' ou 'partial'

        $financials = $this->computeFamilyFinancials($schoolId);

        $owingIds = [];
        foreach ($financials['families'] as $familyId => $f) {
            if ($f['expected'] > 0 && $f['paid'] < $f['expected']) {
                $owingIds[] = $familyId;
            }
        }

        $rolesByFamily = UserRole::where('roleable_type', 'family')
            ->whereIn('roleable_id', $owingIds)
            ->whereHas('role', fn ($q) => $q->whereIn('slug', ['responsible', 'student']))
            ->with(['role:id,slug', 'user.infos' => fn ($q) => $q->where('key', 'phone')])
            ->get()
            ->groupBy('roleable_id');

        $unpaidData = [];

        foreach ($owingIds as $familyId) {
            $f = $financials['families'][$familyId];
            $expectedAmount = $f['expected'];
            $paidAmount = $f['paid'];
            $roles = $rolesByFamily->get($familyId, collect());

            $responsibles = $roles
                ->filter(fn ($ur) => $ur->role->slug === 'responsible' && $ur->user)
                ->map(function ($ur) {
                    $phoneInfo = $ur->user->infos->firstWhere('key', 'phone');
                    return [
                        'id' => $ur->user->id,
                        'name' => trim($ur->user->first_name . ' ' . $ur->user->last_name),
                        'email' => $ur->user->email,
                        'phone' => $phoneInfo?->value,
                    ];
                })->values();

            $unpaidData[] = [
                'id' => $familyId,
                'responsibles' => $responsibles,
                'students_count' => $roles->filter(fn ($ur) => $ur->role->slug === 'student')->count(),
                'expected' => $expectedAmount,
                'paid' => $paidAmount,
                'remaining' => $expectedAmount - $paidAmount,
                'payment_rate' => $expectedAmount > 0 ? round(($paidAmount / $expectedAmount) * 100, 2) : 0,
            ];
        }

        // Apply filters
        $unpaidFamilies = $unpaidData;
        
        // Apply search filter if provided
        if (!empty($search)) {
            $unpaidFamilies = array_filter($unpaidFamilies, function ($family) use ($search) {
                $searchLower = strtolower($search);
                
                // Search in responsible names, emails, and phone numbers
                foreach ($family['responsibles'] as $responsible) {
                    if (str_contains(strtolower($responsible['name']), $searchLower) ||
                        str_contains(strtolower($responsible['email']), $searchLower) ||
                        ($responsible['phone'] && str_contains(strtolower($responsible['phone']), $searchLower))) {
                        return true;
                    }
                }
                
                return false;
            });
        }
        
        // Apply type filter if provided
        if (!empty($filter)) {
            $unpaidFamilies = array_filter($unpaidFamilies, function ($family) use ($filter) {
                if ($filter === 'unpaid') {
                    // Only families with paid = 0
                    return $family['paid'] == 0;
                } elseif ($filter === 'partial') {
                    // Only families with paid > 0 and paid < expected
                    return $family['paid'] > 0 && $family['paid'] < $family['expected'];
                }
                // If filter is not recognized, return all
                return true;
            });
        }
        
        // Reset array keys after filtering
        $unpaidFamilies = array_values($unpaidFamilies);

        // Implement pagination
        $total = count($unpaidFamilies);
        $totalPages = ceil($total / $perPage);
        $offset = ($page - 1) * $perPage;
        
        // Get paginated items
        $paginatedItems = array_slice($unpaidFamilies, $offset, $perPage);

        return response()->json([
            'status' => 'success',
            'data' => [
                'items' => $paginatedItems,
                'pagination' => [
                    'current_page' => (int) $page,
                    'total_pages' => (int) $totalPages,
                    'per_page' => (int) $perPage,
                    'total' => $total
                ]
            ]
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
        $schoolId = currentSchoolId();

        $query = LignePaiement::where('type_paiement', 'cheque')
            ->whereHas('paiement.family', function ($q) use ($schoolId) {
                $q->where('school_id', $schoolId);
            })
            ->with('paiement.family');

        switch ($searchType) {
            case 'cheque_number':
                $query->where('details->numero', 'like', '%' . $searchValue . '%');
                break;
            case 'emitter_name':
                $query->where(function ($q) use ($searchValue) {
                    $q->where('details->nom_emetteur', 'like', '%' . $searchValue . '%')
                      ->orWhere('details->emetteur', 'like', '%' . $searchValue . '%');
                });
                break;
            case 'bank':
                $query->where('details->banque', 'like', '%' . $searchValue . '%');
                break;
        }

        $lignes = $query->get();

        $familyIds = $lignes->map(fn ($ligne) => $ligne->paiement?->family?->id)
            ->filter()
            ->unique()
            ->values();

        $responsiblesByFamily = UserRole::where('roleable_type', 'family')
            ->whereIn('roleable_id', $familyIds)
            ->whereHas('role', fn ($q) => $q->where('slug', 'responsible'))
            ->with(['user.infos' => fn ($q) => $q->where('key', 'phone')])
            ->get()
            ->groupBy('roleable_id');

        $results = $lignes->map(function ($ligne) use ($responsiblesByFamily) {
            $details = $ligne->details ?: [];
            $family = $ligne->paiement?->family;
            $responsibles = collect();

            if ($family) {
                $responsibles = $responsiblesByFamily->get($family->id, collect())
                    ->filter(fn ($ur) => $ur->user)
                    ->map(function ($ur) {
                        $phoneInfo = $ur->user->infos->firstWhere('key', 'phone');
                        return [
                            'id' => $ur->user->id,
                            'name' => trim($ur->user->first_name . ' ' . $ur->user->last_name),
                            'email' => $ur->user->email,
                            'phone' => $phoneInfo?->value,
                        ];
                    })->values();
            }

            return [
                'id' => $ligne->id,
                'amount' => $ligne->montant,
                'cheque_details' => [
                    'numero' => $details['numero'] ?? '',
                    'emetteur' => $details['nom_emetteur'] ?? ($details['emetteur'] ?? ''),
                    'banque' => $details['banque'] ?? ''
                ],
                'payment_date' => $ligne->created_at->format('Y-m-d'),
                'family' => $family ? [
                    'id' => $family->id,
                    'responsibles' => $responsibles,
                ] : null,
            ];
        });

        return response()->json([
            'status' => 'success',
            'data' => $results
        ]);
    }

    public function enrollmentTrends(Request $request)
    {
        $schoolId = currentSchoolId();
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
        $schoolId = currentSchoolId();
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

    public function payments(Request $request)
    {
        $schoolId = currentSchoolId();
        $page = $request->input('page', 1);
        $perPage = min((int) $request->input('per_page', 10), 100);
        $search = $request->input('search', '');
        $paymentType = $request->input('payment_type', '');
        $banks = $request->input('banks', '');
        $exonerationType = $request->input('exoneration_type', '');

        $query = LignePaiement::whereHas('paiement.family', function ($q) use ($schoolId) {
            $q->where('school_id', $schoolId);
        })
        ->with('paiement.family');

        // Filtrer par recherche
        if (!empty($search)) {
            $query->where(function ($q) use ($search) {
                // Pour les chèques, rechercher par numéro
                $q->where(function ($subQ) use ($search) {
                    $subQ->where('type_paiement', 'cheque')
                         ->where('details->numero', 'like', '%' . $search . '%');
                })
                // Pour tous les types, rechercher dans les familles
                ->orWhereHas('paiement.family', function ($familyQ) use ($search) {
                    $familyQ->where(function ($q) use ($search) {
                        // Rechercher les familles qui ont des responsables correspondants
                        $q->whereExists(function ($query) use ($search) {
                            $query->select(DB::raw(1))
                                  ->from('users')
                                  ->join('user_roles', function ($join) {
                                      $join->on('users.id', '=', 'user_roles.user_id')
                                           ->where('user_roles.roleable_type', '=', 'family');
                                  })
                                  ->join('roles', 'user_roles.role_id', '=', 'roles.id')
                                  ->whereColumn('user_roles.roleable_id', 'families.id')
                                  ->where('roles.slug', 'responsible')
                                  ->where(function ($userQ) use ($search) {
                                      $userQ->where('users.first_name', 'like', '%' . $search . '%')
                                            ->orWhere('users.last_name', 'like', '%' . $search . '%')
                                            ->orWhere(DB::raw("CONCAT(users.first_name, ' ', users.last_name)"), 'like', '%' . $search . '%');
                                  });
                        })
                        // Ou rechercher par téléphone
                        ->orWhereExists(function ($query) use ($search) {
                            $query->select(DB::raw(1))
                                  ->from('users')
                                  ->join('user_roles', function ($join) {
                                      $join->on('users.id', '=', 'user_roles.user_id')
                                           ->where('user_roles.roleable_type', '=', 'family');
                                  })
                                  ->join('user_infos', 'users.id', '=', 'user_infos.user_id')
                                  ->whereColumn('user_roles.roleable_id', 'families.id')
                                  ->where('user_infos.key', 'phone')
                                  ->where('user_infos.value', 'like', '%' . $search . '%');
                        });
                    });
                });
            });
        }

        // Filtrer par type de paiement
        if (!empty($paymentType)) {
            $query->where('type_paiement', $paymentType);
        }

        // Filtrer par banques (pour les chèques uniquement)
        if (!empty($banks)) {
            $banksArray = explode(',', $banks);
            $query->where('type_paiement', 'cheque')
                  ->where(function ($q) use ($banksArray) {
                      foreach ($banksArray as $bank) {
                          $q->orWhere('details->banque', 'like', '%' . trim($bank) . '%');
                      }
                  });
        }

        $needsExonerationFilter = $paymentType === 'exoneration' && in_array($exonerationType, ['complete', 'partial']);

        if ($needsExonerationFilter) {
            $formatted = $this->formatPaymentLignes(
                $query->orderBy('created_at', 'desc')->get()
            )->filter(function ($payment) use ($exonerationType) {
                $isComplete = $payment['total_expected'] > 0
                    ? $payment['amount'] >= $payment['total_expected']
                    : true;
                return $exonerationType === 'complete' ? $isComplete : !$isComplete;
            })->values();

            $total = $formatted->count();
            $totalPages = (int) ceil($total / $perPage);
            $items = $formatted->slice(($page - 1) * $perPage, $perPage)->values();
        } else {
            $total = $query->count();
            $totalPages = (int) ceil($total / $perPage);
            $offset = ($page - 1) * $perPage;

            $items = $this->formatPaymentLignes(
                $query->orderBy('created_at', 'desc')->skip($offset)->take($perPage)->get()
            );
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'items' => $items,
                'pagination' => [
                    'current_page' => (int) $page,
                    'total_pages' => $totalPages,
                    'per_page' => (int) $perPage,
                    'total' => $total
                ]
            ]
        ]);
    }

    private function formatPaymentLignes($lignes)
    {
        $familyIds = $lignes->map(fn ($ligne) => $ligne->paiement?->family?->id)
            ->filter()
            ->unique()
            ->values();

        $rolesByFamily = UserRole::where('roleable_type', 'family')
            ->whereIn('roleable_id', $familyIds)
            ->whereHas('role', fn ($q) => $q->whereIn('slug', ['responsible', 'student']))
            ->with(['role:id,slug', 'user.infos' => fn ($q) => $q->where('key', 'phone')])
            ->get()
            ->groupBy('roleable_id');

        $expectedCache = [];

        return $lignes->map(function ($ligne) use ($rolesByFamily, &$expectedCache) {
            $family = $ligne->paiement?->family;
            $responsibles = [];
            $students = [];
            $totalExpected = 0;

            if ($family) {
                $roles = $rolesByFamily->get($family->id, collect());

                $responsibles = $roles
                    ->filter(fn ($ur) => $ur->role->slug === 'responsible' && $ur->user)
                    ->map(function ($ur) {
                        $phoneInfo = $ur->user->infos->firstWhere('key', 'phone');
                        return [
                            'id' => $ur->user->id,
                            'name' => trim($ur->user->first_name . ' ' . $ur->user->last_name),
                            'email' => $ur->user->email,
                            'phone' => $phoneInfo?->value,
                        ];
                    })->values()->toArray();

                $students = $roles
                    ->filter(fn ($ur) => $ur->role->slug === 'student' && $ur->user)
                    ->map(fn ($ur) => [
                        'id' => $ur->user->id,
                        'name' => trim($ur->user->first_name . ' ' . $ur->user->last_name),
                    ])->values()->toArray();

                if (!isset($expectedCache[$family->id])) {
                    $expectedCache[$family->id] = $this->calculateFamilyExpectedAmount($family);
                }
                $totalExpected = $expectedCache[$family->id];
            }

            return [
                'id' => $ligne->id,
                'amount' => $ligne->montant,
                'type' => $ligne->type_paiement,
                'payment_date' => $ligne->created_at->format('Y-m-d H:i'),
                'details' => $ligne->details ?: [],
                'family' => $family ? [
                    'id' => $family->id,
                    'responsibles' => $responsibles,
                    'students' => $students,
                ] : null,
                'total_expected' => $totalExpected,
            ];
        });
    }

    public function availableBanks(Request $request)
    {
        $schoolId = currentSchoolId();

        // Récupérer toutes les banques uniques pour les paiements par chèque
        $banks = LignePaiement::whereHas('paiement.family', function ($q) use ($schoolId) {
            $q->where('school_id', $schoolId);
        })
        ->where('type_paiement', 'cheque')
        ->whereNotNull('details->banque')
        ->get()
        ->pluck('details.banque')
        ->unique()
        ->filter()
        ->values()
        ->toArray(); // Convertir en array

        // S'assurer qu'on a des banques, sinon retourner une liste par défaut
        if (empty($banks)) {
            $banks = ['BNP Paribas', 'Crédit Agricole', 'Société Générale', 'LCL', 'CIC'];
        }

        // Trier les banques
        sort($banks);

        return response()->json([
            'status' => 'success',
            'data' => $banks
        ]);
    }
}