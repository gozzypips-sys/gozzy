<?php
/**
 * Gozzy Group — Testimonials & Comments API
 * Handles: list, post testimonial, reply, delete (testimonial/reply)
 * Stored in: testimonials.json (next to this file)
 *
 * Endpoints:
 *   GET  /testimonials.php             → list all
 *   POST /testimonials.php             → action based on form_type
 *     form_type=testimonial            → create
 *     form_type=reply                  → add reply
 *     form_type=delete_testimonial     → admin delete
 *     form_type=delete_reply           → admin delete reply
 */

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$TESTIMONIALS_FILE = __DIR__ . '/testimonials.json';

function jsonOut($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function cleanStr($v) {
    return htmlspecialchars(trim((string)$v), ENT_QUOTES, 'UTF-8');
}

function loadData($file) {
    if (!file_exists($file)) return ['items' => []];
    $raw = @file_get_contents($file);
    if (!$raw) return ['items' => []];
    $data = json_decode($raw, true);
    return (is_array($data) && isset($data['items'])) ? $data : ['items' => []];
}

function saveData($file, $data) {
    @file_put_contents(
        $file,
        json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        LOCK_EX
    );
}

// ============================================================
// SEED — Mr. Kind's testimonial (added once if file is empty)
// ============================================================
$SEED = [
    'id'      => 'seed-mrkind',
    'name'    => 'Mr. Kind',
    'role'    => 'Business Owner · 2 Projects with Gozzy',
    'rating'  => 5,
    'message' => 'Thank you Gozzy Group — it feels easier to do my business now. The systems you built for me just work, and I don\'t have to think about them.',
    'date'    => (time() - 172800) * 1000, // 2 days ago
    'replies' => [],
];

// ============================================================
// GET — list all testimonials
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $data = loadData($TESTIMONIALS_FILE);

    // Seed Mr. Kind if file is brand new
    if (empty($data['items'])) {
        $data['items'][] = $SEED;
        saveData($TESTIMONIALS_FILE, $data);
    }

    // Sort newest first
    usort($data['items'], fn($a, $b) => ($b['date'] ?? 0) - ($a['date'] ?? 0));

    jsonOut(['items' => $data['items']]);
}

// ============================================================
// POST — actions
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $type = $_POST['form_type'] ?? '';
    $data = loadData($TESTIMONIALS_FILE);

    // Ensure seed is always present if the store is empty
    if (empty($data['items'])) {
        $data['items'][] = $SEED;
    }

    // ---- Create new testimonial ----
    if ($type === 'testimonial') {
        $name    = cleanStr($_POST['name']    ?? '');
        $role    = cleanStr($_POST['role']    ?? '');
        $message = cleanStr($_POST['message'] ?? '');
        $rating  = (int)($_POST['rating'] ?? 5);
        if ($rating < 1) $rating = 1;
        if ($rating > 5) $rating = 5;

        if ($name === '' || $message === '') {
            jsonOut(['status' => 'error', 'message' => 'Please fill in your name and testimonial.'], 400);
        }
        if (mb_strlen($name) > 60) $name = mb_substr($name, 0, 60);
        if (mb_strlen($message) > 800) $message = mb_substr($message, 0, 800);

        $data['items'][] = [
            'id'      => 't-' . time() . '-' . substr(md5(uniqid('', true)), 0, 6),
            'name'    => $name,
            'role'    => $role,
            'rating'  => $rating,
            'message' => $message,
            'date'    => time() * 1000,
            'replies' => [],
        ];
        saveData($TESTIMONIALS_FILE, $data);
        jsonOut(['status' => 'success', 'message' => 'Testimonial posted.']);
    }

    // ---- Add reply ----
    if ($type === 'reply') {
        $tid     = cleanStr($_POST['testimonial_id'] ?? '');
        $name    = cleanStr($_POST['name']    ?? 'Anonymous');
        $message = cleanStr($_POST['message'] ?? '');
        $isAdmin = isset($_POST['is_admin']) && $_POST['is_admin'] === '1';

        if ($tid === '' || $message === '') {
            jsonOut(['status' => 'error', 'message' => 'Missing reply data.'], 400);
        }
        if (mb_strlen($message) > 500) $message = mb_substr($message, 0, 500);
        if (mb_strlen($name) > 60) $name = mb_substr($name, 0, 60);

        $found = false;
        foreach ($data['items'] as &$item) {
            if (($item['id'] ?? '') === $tid) {
                if (!isset($item['replies'])) $item['replies'] = [];
                $item['replies'][] = [
                    'id'      => 'r-' . time() . '-' . substr(md5(uniqid('', true)), 0, 4),
                    'name'    => $name,
                    'message' => $message,
                    'isAdmin' => $isAdmin,
                    'date'    => time() * 1000,
                ];
                $found = true;
                break;
            }
        }
        unset($item);
        if (!$found) jsonOut(['status' => 'error', 'message' => 'Testimonial not found.'], 404);

        saveData($TESTIMONIALS_FILE, $data);
        jsonOut(['status' => 'success', 'message' => 'Reply posted.']);
    }

    // ---- Delete a testimonial (admin) ----
    if ($type === 'delete_testimonial') {
        $id = cleanStr($_POST['id'] ?? '');
        if ($id === '') jsonOut(['status' => 'error', 'message' => 'Missing ID.'], 400);
        $data['items'] = array_values(array_filter(
            $data['items'],
            fn($i) => ($i['id'] ?? '') !== $id
        ));
        saveData($TESTIMONIALS_FILE, $data);
        jsonOut(['status' => 'success', 'message' => 'Testimonial deleted.']);
    }

    // ---- Delete a reply (admin) ----
    if ($type === 'delete_reply') {
        $tid = cleanStr($_POST['testimonial_id'] ?? '');
        $rid = cleanStr($_POST['reply_id'] ?? '');
        if ($tid === '' || $rid === '') jsonOut(['status' => 'error', 'message' => 'Missing IDs.'], 400);
        foreach ($data['items'] as &$item) {
            if (($item['id'] ?? '') === $tid && isset($item['replies'])) {
                $item['replies'] = array_values(array_filter(
                    $item['replies'],
                    fn($r) => ($r['id'] ?? '') !== $rid
                ));
                break;
            }
        }
        unset($item);
        saveData($TESTIMONIALS_FILE, $data);
        jsonOut(['status' => 'success', 'message' => 'Reply deleted.']);
    }

    jsonOut(['status' => 'error', 'message' => 'Unknown action.'], 400);
}

jsonOut(['status' => 'error', 'message' => 'Invalid request.'], 400);
