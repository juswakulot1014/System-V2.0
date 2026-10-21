<?php
declare(strict_types=1);

/**
 * seed_same_program.php — Generates 10 mock students sharing the same program & section.
 * Run this ONCE, then DELETE the file.
 */
require_once __DIR__ . '/config/bootstrap.php';

header('Content-Type: text/plain; charset=UTF-8');

// Shared Program Data
$shared_strand  = 'ICT Support and Computer Programming Technologies';
$shared_program = 'Computer System Servicing';
$shared_section = 'CSS A';
$shared_sy      = '2026-2027';
$shared_track   = 'TECHPRO ELECTIVES';

$students = [
    // --- Grade 11 (5 students) ---
    ['lrn' => '987654321001', 'first' => 'Liam', 'last' => 'Anderson', 'mid' => 'Smith', 'sex' => 'Male', 'grade' => 'Grade 11'],
    ['lrn' => '987654321002', 'first' => 'Olivia', 'last' => 'Martinez', 'mid' => 'Garcia', 'sex' => 'Female', 'grade' => 'Grade 11'],
    ['lrn' => '987654321003', 'first' => 'Noah', 'last' => 'Taylor', 'mid' => 'Brown', 'sex' => 'Male', 'grade' => 'Grade 11'],
    ['lrn' => '987654321004', 'first' => 'Emma', 'last' => 'Wilson', 'mid' => 'Davis', 'sex' => 'Female', 'grade' => 'Grade 11'],
    ['lrn' => '987654321005', 'first' => 'Lucas', 'last' => 'Thomas', 'mid' => 'Moore', 'sex' => 'Male', 'grade' => 'Grade 11'],
    
    // --- Grade 12 (5 students) ---
    ['lrn' => '987654321006', 'first' => 'Sophia', 'last' => 'Jackson', 'mid' => 'White', 'sex' => 'Female', 'grade' => 'Grade 12'],
    ['lrn' => '987654321007', 'first' => 'Mason', 'last' => 'Harris', 'mid' => 'Clark', 'sex' => 'Male', 'grade' => 'Grade 12'],
    ['lrn' => '987654321008', 'first' => 'Isabella', 'last' => 'Lewis', 'mid' => 'Walker', 'sex' => 'Female', 'grade' => 'Grade 12'],
    ['lrn' => '987654321009', 'first' => 'Ethan', 'last' => 'Robinson', 'mid' => 'Hall', 'sex' => 'Male', 'grade' => 'Grade 12'],
    ['lrn' => '987654321010', 'first' => 'Mia', 'last' => 'Allen', 'mid' => 'Young', 'sex' => 'Female', 'grade' => 'Grade 12'],
];

$inserted = 0;

$conn->begin_transaction();

try {
    foreach ($students as $index => $s) {
        $seq = $index + 1;
        
        // 1. Insert into students_info
        $stmt = $conn->prepare("
            INSERT INTO students_info 
            (lrn, first_name, last_name, middle_name, sex, birth_date, age, civil_status, nationality, religion, email, phone, student_id_number) 
            VALUES (?, ?, ?, ?, ?, '2008-05-15', 18, 'Single', 'Filipino', 'Roman Catholic', ?, ?, ?)
        ");
        $email = strtolower($s['first'] . '.' . $s['last'] . '@example.com');
        $phone = '091800000' . str_pad((string)$seq, 2, '0', STR_PAD_LEFT);
        $student_id_number = 'UCSCI-2026-' . str_pad((string)(1000 + $seq), 6, '0', STR_PAD_LEFT);
        
        // 8 placeholders = 8 's' types
        $stmt->bind_param('ssssssss', $s['lrn'], $s['first'], $s['last'], $s['mid'], $s['sex'], $email, $phone, $student_id_number);
        $stmt->execute();
        $student_id = $conn->insert_id;
        $stmt->close();

        // 2. Insert into addresses
        $stmt = $conn->prepare("
            INSERT INTO addresses (student_id, purok_street, barangay, town_city, province, region, district, postal_code) 
            VALUES (?, 'Purok 2', 'Brgy. Poblacion', 'Sagay City', 'Negros Occidental', 'Region VI', 'District 2', '6122')
        ");
        $stmt->bind_param('i', $student_id);
        $stmt->execute();
        $stmt->close();

        // 3. Insert into parents_info
        $stmt = $conn->prepare("
            INSERT INTO parents_info 
            (student_id, father_name, father_occupation, mother_maiden_name, mother_occupation, guardian_fullname, guardian_relation, guardian_contact, ave_family_income, is_4ps) 
            VALUES (?, ?, 'Driver', ?, 'Teacher', ?, 'Parent', ?, 20000.00, 0)
        ");
        $father = 'Mr. ' . $s['last'];
        $mother = 'Mrs. ' . $s['last'];
        $guardian = 'Mr. ' . $s['last'];
        $gcontact = $phone;
        $stmt->bind_param('issss', $student_id, $father, $mother, $guardian, $gcontact);
        $stmt->execute();
        $stmt->close();

        // 4. Insert into enrollment_form
        $term = '1st Term';
        $status = 'Active';
        
        $stmt = $conn->prepare("
            INSERT INTO enrollment_form 
            (student_id, school_year, grade_level, term, track, strand, program, section, status) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        // 1 'i' (student_id), 8 's' (sy, grade, term, track, strand, program, section, status) = 9 total
        $stmt->bind_param('issssssss', $student_id, $shared_sy, $s['grade'], $term, $shared_track, $shared_strand, $shared_program, $shared_section, $status);
        $stmt->execute();
        $stmt->close();

        $inserted++;
        echo "Inserted: {$s['first']} {$s['last']} ({$s['grade']} - {$shared_section})\n";
    }

    $conn->commit();
    echo "\n✅ Successfully seeded {$inserted} students into {$shared_program}.\n";
    echo "⚠️  DELETE THIS FILE NOW.\n";

} catch (Throwable $e) {
    $conn->rollback();
    echo "\n❌ Error: " . $e->getMessage() . "\n";
}