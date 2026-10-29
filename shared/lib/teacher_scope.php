<?php
// shared/lib/teacher_scope.php — teacher↔section scoping.
// Requires $conn (mysqli) and $_SESSION['admin_id'] to be available.

declare(strict_types=1);

function teacher_assigned_sections(mysqli $conn, int $teacher_id, ?string $school_year = null): array
{
    $sql = "SELECT section, grade_level, school_year, program
              FROM teacher_sections
             WHERE teacher_id = ?";
    $types  = 'i';
    $params = [$teacher_id];

    if ($school_year !== null && $school_year !== '') {
        $sql .= " AND school_year = ?";
        $types .= 's';
        $params[] = $school_year;
    }

    $stmt = $conn->prepare($sql);
    if (!$stmt) { error_log('teacher_scope: ' . $conn->error); return []; }
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function teacher_scope_filter(mysqli $conn, int $teacher_id, string $alias = 'e', ?string $school_year = null): array
{
    $sections = teacher_assigned_sections($conn, $teacher_id, $school_year);
    if (empty($sections)) {
        return ['sql' => '1=0', 'types' => '', 'params' => []];
    }

    $parts  = [];
    $types  = '';
    $params = [];
    foreach ($sections as $s) {
        $parts[] = "({$alias}.section = ? AND {$alias}.grade_level = ? AND {$alias}.school_year = ? AND COALESCE({$alias}.program,'') = ?)";
        array_push($params, $s['section'], $s['grade_level'], $s['school_year'], $s['program']);
        $types .= 'ssss';
    }

    return [
        'sql'    => '(' . implode(' OR ', $parts) . ')',
        'types'  => $types,
        'params' => $params,
    ];
}

function teacher_has_student(mysqli $conn, int $teacher_id, int $student_id, ?int $enrollment_id = null): bool
{
    $sql = "
        SELECT 1
          FROM enrollment_form e
         WHERE e.student_id = ?
           AND EXISTS (
               SELECT 1 FROM teacher_sections ts
                WHERE ts.teacher_id  = ?
                  AND ts.section     = e.section
                  AND ts.grade_level = e.grade_level
                  AND ts.school_year = e.school_year
                  AND ts.program     = COALESCE(e.program,'')
           )
    ";
    $types  = 'ii';
    $params = [$student_id, $teacher_id];

    if ($enrollment_id !== null && $enrollment_id > 0) {
        $sql .= " AND e.enrollment_id = ?";
        $types .= 'i';
        $params[] = $enrollment_id;
    }
    $sql .= " LIMIT 1";

    $stmt = $conn->prepare($sql);
    if (!$stmt) { error_log('teacher_scope: ' . $conn->error); return false; }
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $ok = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $ok;
}

function teacher_student_ids(mysqli $conn, int $teacher_id): array
{
    $sql = "
        SELECT DISTINCT e.student_id
          FROM enrollment_form e
          JOIN teacher_sections ts
            ON ts.section      = e.section
           AND ts.grade_level  = e.grade_level
           AND ts.school_year  = e.school_year
           AND ts.program      = COALESCE(e.program,'')
         WHERE ts.teacher_id = ?
    ";
    $stmt = $conn->prepare($sql);
    if (!$stmt) { return []; }
    $stmt->bind_param('i', $teacher_id);
    $stmt->execute();
    $ids = array_map('intval', array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'student_id'));
    $stmt->close();
    return $ids;
}
