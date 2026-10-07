<?php
session_start();
require_once('conn_inc.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['student_id']) && isset($_POST['arrange_exam_id']) && isset($_POST['csrf_token']) && $_POST['csrf_token'] === $_SESSION['csrf_token']) {
    $student_id = intval($_POST['student_id']);
    $arrange_exam_id = intval($_POST['arrange_exam_id']);
    $subjects = [];

    // Fetch subjects
    $result = $conn->query("SELECT id, title, type, total_marks FROM subjects ORDER BY title");
    while ($row = $result->fetch_assoc()) {
        $subject_id = $row['id'];
        $subjects[$subject_id] = [
            'id' => $subject_id,
            'title' => $row['title'],
            'type' => $row['type'],
            'total_marks' => $row['total_marks'] ?? 100,
            'marks' => null,
            'sub_subjects' => []
        ];

        // Fetch sub-subjects
        $stmt = $conn->prepare("SELECT id, title, marks AS total_marks FROM sub_subjects WHERE subject_id = ? ORDER BY title");
        $stmt->bind_param("i", $subject_id);
        $stmt->execute();
        $sub_result = $stmt->get_result();
        while ($sub_row = $sub_result->fetch_assoc()) {
            $subjects[$subject_id]['sub_subjects'][] = [
                'id' => $sub_row['id'],
                'title' => $sub_row['title'],
                'total_marks' => $sub_row['total_marks'] ?? 50,
                'marks' => null
            ];
        }
        $stmt->close();
    }
    $result->close();

    // Fetch existing results
    $stmt = $conn->prepare("SELECT subject_id, sub_subject_id, marks FROM results WHERE student_id = ? AND arrange_exam_id = ?");
    $stmt->bind_param("ii", $student_id, $arrange_exam_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $subject_id = $row['subject_id'];
        $sub_subject_id = $row['sub_subject_id'];
        if ($sub_subject_id == 0) {
            if (isset($subjects[$subject_id])) {
                $subjects[$subject_id]['marks'] = $row['marks'];
            }
        } else {
            if (isset($subjects[$subject_id])) {
                foreach ($subjects[$subject_id]['sub_subjects'] as &$sub_subject) {
                    if ($sub_subject['id'] == $sub_subject_id) {
                        $sub_subject['marks'] = $row['marks'];
                    }
                }
            }
        }
    }
    $stmt->close();

    echo json_encode(['success' => true, 'subjects' => array_values($subjects)]);
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request or CSRF token.']);
}
$conn->close();
?>