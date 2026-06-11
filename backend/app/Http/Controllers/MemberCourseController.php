<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\Enrollment;
use App\Models\Course;
use Illuminate\Support\Facades\DB;

class MemberCourseController extends Controller
{
    public function kelas()
    {
        $member = Auth::guard('web')->user();
        
        $enrollments_data = Enrollment::with('course')
            ->where('user_id', $member->id)
            ->orderBy('enrolled_at', 'desc')
            ->get();
            
        $enrollments = $enrollments_data->map(function($e) use ($member) {
            $cert = DB::table('gb_certificates')->where('course_id', $e->course_id)->where('user_id', $member->id)->first();
            return [
                'id' => $e->id,
                'course_id' => $e->course_id,
                'status' => $e->status,
                'enrolled_at' => $e->enrolled_at,
                'progress_percent' => $e->progress_percent ?? 0,
                'completed_modules' => $e->completed_modules ?? 0,
                'current_module' => $e->current_module,
                'title' => $e->course->title,
                'category' => $e->course->category,
                'duration_hours' => $e->course->duration_hours,
                'total_modules' => $e->course->total_modules,
                'mentor_name' => $e->course->mentor_name ?? '',
                'rating' => $e->course->rating,
                'total_reviews' => $e->course->total_reviews ?? 0,
                'is_free' => $e->course->is_free,
                'pdf_path' => $cert ? $cert->pdf_path : null
            ];
        })->toArray();
        
        $available_courses = Course::whereDoesntHave('enrollments', function($q) use ($member) {
                $q->where('user_id', $member->id);
            })
            ->where('status', 'active')
            ->limit(6)
            ->get()
            ->toArray();
            
        $total_kelas = count($enrollments);
        $kelas_selesai = collect($enrollments)->where('status', 'completed')->count();
        
        // Also if progress_percent >= 100 it's considered complete in the view
        $kelas_selesai = collect($enrollments)->filter(function($en) {
            $pct = $en['total_modules'] > 0 ? round(($en['completed_modules'] / $en['total_modules']) * 100) : 0;
            return $en['status'] === 'completed' || $pct >= 100;
        })->count();

        return view('member.kelas', compact('member', 'enrollments', 'available_courses', 'total_kelas', 'kelas_selesai'));
    }

    public function modul(Request $request)
    {
        $member = Auth::guard('web')->user();
        $courseId = $request->query('course_id');
        $modules = [];
        $course = null;

        if ($courseId) {
            $course = Course::find($courseId);
            if ($course) {
                $modules = $course->modules()->orderBy('order')->get();
            }
        }

        return view('member.modul', compact('member', 'course', 'modules'));
    }

    public function sertifikat()
    {
        $member = Auth::guard('web')->user();
        
        $certificates = DB::table('gb_certificates as cert')
            ->select('cert.*', 'c.title as course_title', 'c.category', 'c.thumbnail', 'c.mentor_name as author', 
                DB::raw('(SELECT ROUND(AVG(score)) FROM gb_quiz_results WHERE course_id = cert.course_id AND user_id = cert.user_id) as final_score'))
            ->join('gb_courses as c', 'cert.course_id', '=', 'c.id')
            ->where('cert.user_id', $member->id)
            ->orderBy('cert.issued_at', 'desc')
            ->get()
            ->map(function($cert) {
                return (array) $cert;
            })->toArray();

        return view('member.sertifikat', compact('member', 'certificates'));
    }

    public function getCourseModules(Request $request)
    {
        $member = Auth::guard('web')->user();
        $course_id = (int)$request->query('course_id', 0);

        if ($course_id === 0) {
            $latest = Enrollment::where('user_id', $member->id)
                ->orderBy('enrolled_at', 'desc')
                ->first();
            if ($latest) {
                $course_id = $latest->course_id;
            }
        }

        if ($course_id === 0) {
            return response()->json(['success' => false, 'message' => 'No active course found']);
        }

        // Fetch Course Info
        $course = Course::join('gb_enrollments as e', 'gb_courses.id', '=', 'e.course_id')
            ->where('gb_courses.id', $course_id)
            ->where('e.user_id', $member->id)
            ->select('gb_courses.*', 'e.progress_percent', 'e.completed_modules', 'e.status as enrollment_status')
            ->first();

        if (!$course) {
            return response()->json(['success' => false, 'message' => 'Course not found or not enrolled']);
        }

        // Fetch Modules
        $modules = DB::table('gb_modules')
            ->where('course_id', $course_id)
            ->orderBy('module_number', 'asc')
            ->get()
            ->map(function ($row) {
                $rowArr = (array) $row;
                if (!empty($rowArr['quiz_data'])) {
                    $rowArr['quiz_data'] = json_decode($rowArr['quiz_data'], true);
                } else {
                    $rowArr['quiz_data'] = null;
                }
                return $rowArr;
            })->toArray();

        // Fetch Quiz Results for this user & course
        $quiz_results = DB::table('gb_quiz_results')
            ->where('course_id', $course_id)
            ->where('user_id', $member->id)
            ->get()
            ->pluck('score', 'module_number')
            ->toArray();

        // Enforce TANDUR structure
        $tandur_names = [
            1 => 'Tumbuhkan - Pertemuan ke 1 (Pendahuluan)',
            2 => 'Alami - Pertemuan Ke 2 (Konsep Dasar)',
            3 => 'Namai - Pertemuan ke 3 (Strategi Penerapan)',
            4 => 'Demonstrasikan - Studi Kasus',
            5 => 'Ulangi - Evaluasi',
            6 => 'Rayakan - Penutup'
        ];

        $tandur_modules = [];
        for ($i = 1; $i <= 6; $i++) {
            $mod = null;
            foreach ($modules as $m) {
                if ((int)$m['module_number'] === $i) {
                    $mod = $m;
                    break;
                }
            }
            if (!$mod && isset($modules[$i-1])) {
                $mod = $modules[$i-1];
            }

            if ($mod) {
                $mod['tandur_name'] = $tandur_names[$i];
                $mod['user_score'] = isset($quiz_results[$i]) ? (int)$quiz_results[$i] : null;
                $hasTakenQuiz = ($mod['user_score'] !== null);
                $mod['is_completed'] = ($i <= (int)$course->completed_modules) || $hasTakenQuiz;
                $mod['is_locked'] = ($i > (int)$course->completed_modules + 1);

                if (empty($mod['quiz_data'])) {
                    $mod['quiz_data'] = [
                        [
                            "id" => "q1",
                            "question" => "Apa prinsip utama dari tahap " . $tandur_names[$i] . "?",
                            "options" => [
                                ["id" => "a", "text" => "Menghafal materi tanpa praktik"],
                                ["id" => "b", "text" => "Menerapkan pembelajaran bermakna bagi siswa"],
                                ["id" => "c", "text" => "Mengerjakan tugas administratif"],
                                ["id" => "d", "text" => "Memberikan nilai instan"]
                            ],
                            "answer" => "b"
                        ],
                        [
                            "id" => "q2",
                            "question" => "Bagaimana cara terbaik mengevaluasi pemahaman siswa di tahap ini?",
                            "options" => [
                                ["id" => "a", "text" => "Asesmen Formatif yang berkesinambungan"],
                                ["id" => "b", "text" => "Ujian dadakan yang sulit"],
                                ["id" => "c", "text" => "Memberikan hukuman"],
                                ["id" => "d", "text" => "Hanya menggunakan nilai akhir"]
                            ],
                            "answer" => "a"
                        ]
                    ];
                }
                $tandur_modules[] = $mod;
            } else {
                $tandur_modules[] = [
                    'id' => 0,
                    'course_id' => $course_id,
                    'module_number' => $i,
                    'title' => $tandur_names[$i] . ' (Coming Soon)',
                    'tandur_name' => $tandur_names[$i],
                    'duration_minutes' => 0,
                    'video_url' => '',
                    'content' => '<p>Selamat datang di tahap <b>' . $tandur_names[$i] . '</b>.</p><p>Materi ini didesain khusus agar Anda dapat memahami konsep secara mendalam. Jangan lupa untuk mencatat poin-poin penting menggunakan fitur Catatan di bawah ini, dan kerjakan Quiz setelah Anda selesai membaca materi ini untuk mengukur pemahaman Anda.</p>',
                    'user_score' => isset($quiz_results[$i]) ? (int)$quiz_results[$i] : null,
                    'is_locked' => ($i > (int)$course->completed_modules + 1),
                    'is_completed' => ($i <= (int)$course->completed_modules) || isset($quiz_results[$i]),
                    'quiz_data' => [
                        [
                            "id" => "q1",
                            "question" => "Apa prinsip utama dari tahap " . $tandur_names[$i] . "?",
                            "options" => [
                                ["id" => "a", "text" => "Menghafal materi tanpa praktik"],
                                ["id" => "b", "text" => "Menerapkan pembelajaran bermakna bagi siswa"],
                                ["id" => "c", "text" => "Mengerjakan tugas administratif"],
                                ["id" => "d", "text" => "Memberikan nilai instan"]
                            ],
                            "answer" => "b"
                        ]
                    ]
                ];
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'OK',
            'course' => $course->toArray(),
            'modules' => $tandur_modules
        ]);
    }

    public function getAllQuizzes(Request $request)
    {
        $member = Auth::guard('web')->user();

        $enrolled_courses = Course::join('gb_enrollments as e', 'gb_courses.id', '=', 'e.course_id')
            ->where('e.user_id', $member->id)
            ->orderBy('e.enrolled_at', 'desc')
            ->select('gb_courses.*', 'e.progress_percent', 'e.completed_modules', 'e.status as enrollment_status')
            ->get();

        $tandur_names = [
            1 => 'Tumbuhkan - Pertemuan ke 1 (Pendahuluan)',
            2 => 'Alami - Pertemuan Ke 2 (Konsep Dasar)',
            3 => 'Namai - Pertemuan ke 3 (Strategi Penerapan)',
            4 => 'Demonstrasikan - Studi Kasus',
            5 => 'Ulangi - Evaluasi',
            6 => 'Rayakan - Penutup'
        ];

        $courses_data = [];
        foreach ($enrolled_courses as $course) {
            $course_id = $course->id;

            $modules = DB::table('gb_modules')
                ->where('course_id', $course_id)
                ->orderBy('module_number', 'asc')
                ->get()
                ->map(function ($row) {
                    $rowArr = (array) $row;
                    if (!empty($rowArr['quiz_data'])) {
                        $rowArr['quiz_data'] = json_decode($rowArr['quiz_data'], true);
                    } else {
                        $rowArr['quiz_data'] = null;
                    }
                    return $rowArr;
                })->toArray();

            $quiz_results = DB::table('gb_quiz_results')
                ->where('course_id', $course_id)
                ->where('user_id', $member->id)
                ->get()
                ->pluck('score', 'module_number')
                ->toArray();

            $tandur_modules = [];
            for ($i = 1; $i <= 6; $i++) {
                $mod = null;
                foreach ($modules as $m) {
                    if ((int)$m['module_number'] === $i) {
                        $mod = $m;
                        break;
                    }
                }
                if (!$mod && isset($modules[$i-1])) {
                    $mod = $modules[$i-1];
                }

                if ($mod) {
                    $mod['tandur_name'] = $tandur_names[$i];
                    $mod['user_score'] = isset($quiz_results[$i]) ? (int)$quiz_results[$i] : null;
                    $hasTakenQuiz = ($mod['user_score'] !== null);
                    $mod['is_completed'] = ($i <= (int)$course->completed_modules) || $hasTakenQuiz;
                    $mod['is_locked'] = ($i > (int)$course->completed_modules + 1);

                    if (empty($mod['quiz_data'])) {
                        $mod['quiz_data'] = [
                            [
                                "id" => "q1",
                                "question" => "Apa prinsip utama dari tahap " . $tandur_names[$i] . "?",
                                "options" => [
                                    ["id" => "a", "text" => "Menghafal materi tanpa praktik"],
                                    ["id" => "b", "text" => "Menerapkan pembelajaran bermakna bagi siswa"],
                                    ["id" => "c", "text" => "Mengerjakan tugas administratif"],
                                    ["id" => "d", "text" => "Memberikan nilai instan"]
                                ],
                                "answer" => "b"
                            ],
                            [
                                "id" => "q2",
                                "question" => "Bagaimana cara terbaik mengevaluasi pemahaman siswa di tahap ini?",
                                "options" => [
                                    ["id" => "a", "text" => "Asesmen Formatif yang berkesinambungan"],
                                    ["id" => "b", "text" => "Ujian dadakan yang sulit"],
                                    ["id" => "c", "text" => "Memberikan hukuman"],
                                    ["id" => "d", "text" => "Hanya menggunakan nilai akhir"]
                                ],
                                "answer" => "a"
                            ]
                        ];
                    }
                    $tandur_modules[] = $mod;
                } else {
                    $tandur_modules[] = [
                        'id' => 0,
                        'course_id' => $course_id,
                        'module_number' => $i,
                        'title' => $tandur_names[$i] . ' (Coming Soon)',
                        'tandur_name' => $tandur_names[$i],
                        'duration_minutes' => 0,
                        'video_url' => '',
                        'content' => '<p>Selamat datang di tahap <b>' . $tandur_names[$i] . '</b>.</p><p>Materi ini didesain khusus agar Anda dapat memahami konsep secara mendalam.</p>',
                        'user_score' => isset($quiz_results[$i]) ? (int)$quiz_results[$i] : null,
                        'is_locked' => ($i > (int)$course->completed_modules + 1),
                        'is_completed' => ($i <= (int)$course->completed_modules) || isset($quiz_results[$i]),
                        'quiz_data' => [
                            [
                                "id" => "q1",
                                "question" => "Apa prinsip utama dari tahap " . $tandur_names[$i] . "?",
                                "options" => [
                                    ["id" => "a", "text" => "Menghafal materi tanpa praktik"],
                                    ["id" => "b", "text" => "Menerapkan pembelajaran bermakna bagi siswa"],
                                    ["id" => "c", "text" => "Mengerjakan tugas administratif"],
                                    ["id" => "d", "text" => "Memberikan nilai instan"]
                                ],
                                "answer" => "b"
                            ]
                        ]
                    ];
                }
            }

            $courses_data[] = [
                'course' => $course->toArray(),
                'modules' => $tandur_modules
            ];
        }

        return response()->json([
            'success' => true,
            'courses' => $courses_data
        ]);
    }

    public function getQuizResult(Request $request)
    {
        $member = Auth::guard('web')->user();
        $course_id = (int)$request->query('course_id', 0);
        $module_number = (int)$request->query('module_number', 0);

        if (!$course_id || !$module_number) {
            return response()->json(['success' => false, 'message' => 'Invalid parameters']);
        }

        $row = DB::table('gb_quiz_results')
            ->where('user_id', $member->id)
            ->where('course_id', $course_id)
            ->where('module_number', $module_number)
            ->orderBy('created_at', 'desc')
            ->first();

        if ($row) {
            return response()->json([
                'success' => true,
                'score' => (int)$row->score,
                'answers' => json_decode($row->answers_json, true)
            ]);
        } else {
            return response()->json(['success' => false, 'message' => 'Result not found']);
        }
    }

    public function completeModule(Request $request)
    {
        $member = Auth::guard('web')->user();
        $course_id = (int)$request->input('course_id', 0);
        $module_number = (int)$request->input('module_number', 0);
        $score = (int)$request->input('score', 100);
        $answers_json = $request->input('answers_json', '{}');

        if (!$course_id || !$module_number) {
            return response()->json(['success' => false, 'message' => 'Invalid parameters']);
        }

        // Save quiz result
        DB::table('gb_quiz_results')->insert([
            'user_id' => $member->id,
            'course_id' => $course_id,
            'module_number' => $module_number,
            'score' => $score,
            'answers_json' => $answers_json,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Get current progress
        $enrollment = Enrollment::where('course_id', $course_id)
            ->where('user_id', $member->id)
            ->first();

        if ($enrollment) {
            $current = (int)$enrollment->completed_modules;

            // Only update if they completed a new module AND they scored >= 75
            if ($module_number > $current && $score >= 75) {
                $courseInfo = Course::find($course_id);
                $total_mod = (int)($courseInfo->total_modules ?? 6);
                $progress = min(100, round(($module_number / $total_mod) * 100));

                $enrollment->update([
                    'completed_modules' => $module_number,
                    'progress_percent' => $progress
                ]);

                // Check if this is the last module
                if ($courseInfo && $module_number >= (int)$courseInfo->total_modules) {
                    // Graduated! Update status enrollment
                    $enrollment->update([
                        'status' => 'completed',
                        'progress_percent' => 100
                    ]);

                    // Check if certificate already exists
                    $certExists = DB::table('gb_certificates')
                        ->where('user_id', $member->id)
                        ->where('course_id', $course_id)
                        ->exists();

                    if (!$certExists) {
                        $member_name = $member->full_name ?? 'Peserta';
                        $course_title = $courseInfo->title;
                        $cert_template = $courseInfo->cert_template;
                        $y_pos = (int)($courseInfo->cert_name_y ?? 500);
                        $cert_config = $courseInfo->cert_config;

                        $cert_number = 'GV-' . date('Ymd') . '-' . sprintf('%04d', $member->id) . '-' . sprintf('%04d', $course_id);

                        $pdf_path = null;
                        if (!empty($cert_template)) {
                            $template_path = public_path('uploads/cert_templates/' . $cert_template);
                            if (file_exists($template_path)) {
                                require_once resource_path('views/member/api/generate_certificate.php');
                                $output_filename = 'cert_' . $cert_number . '.jpg';
                                $output_path = public_path('uploads/certificates/' . $output_filename);

                                $date_str = date('d F Y');
                                if (generateCertificate($member_name, $course_title, $cert_number, $date_str, $template_path, $output_path, $y_pos, $cert_config)) {
                                    $pdf_path = $output_filename;
                                }
                            }
                        }

                        // Save certificate to database
                        DB::table('gb_certificates')->insert([
                            'user_id' => $member->id,
                            'course_id' => $course_id,
                            'certificate_number' => $cert_number,
                            'pdf_path' => $pdf_path,
                            'issued_at' => now(),
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                }
            }

            return response()->json(['success' => true]);
        } else {
            return response()->json(['success' => false, 'message' => 'Course enrollment not found']);
        }
    }
}
