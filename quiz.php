<?php
// ============================================
// PROQUIZ - Complete with User History (FIXED)
// File: quiz.php
// ============================================

$host = 'localhost';
$dbname = 'proquiz_db';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    die("Database Connection Failed: " . $e->getMessage());
}

// ---------- SESSION START ----------
session_start();

// ---------- AUTHENTICATION FUNCTIONS ----------
function loginUser($pdo, $username, $password) {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch();
    
    if ($user && password_verify($password, $user['password_hash'])) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        return true;
    }
    return false;
}

function registerUser($pdo, $username, $email, $password) {
    $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
    $stmt->execute([$username, $email]);
    if ($stmt->fetch()) {
        return ['success' => false, 'message' => 'Username or email already exists'];
    }
    
    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("INSERT INTO users (username, email, password_hash) VALUES (?, ?, ?)");
    $stmt->execute([$username, $email, $hashedPassword]);
    
    return ['success' => true, 'message' => 'User registered successfully'];
}

function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function getCurrentUser($pdo) {
    if (isset($_SESSION['user_id'])) {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        return $stmt->fetch();
    }
    return null;
}

// ---------- HANDLE API REQUESTS ----------
if (isset($_GET['api'])) {
    header('Content-Type: application/json');
    
    if (!isLoggedIn()) {
        echo json_encode(['error' => 'Unauthorized', 'message' => 'Please login first']);
        exit;
    }
    
    $user = getCurrentUser($pdo);
    $action = $_GET['action'] ?? '';
    
    switch($action) {
        case 'get_questions':
            $difficulty = $_GET['difficulty'] ?? 'all';
            $limit = (int)$_GET['limit'];
            
            // Ensure limit is between 1 and 50
            if ($limit < 1) $limit = 5;
            if ($limit > 50) $limit = 20;
            
            try {
                $sql = "SELECT * FROM questions";
                if ($difficulty != 'all') {
                    $sql .= " WHERE difficulty = '" . addslashes($difficulty) . "'";
                }
                $sql .= " ORDER BY RAND() LIMIT " . intval($limit);
                
                $stmt = $pdo->query($sql);
                $questions = $stmt->fetchAll();
                
                if (empty($questions)) {
                    echo json_encode([
                        'success' => false, 
                        'error' => 'No questions found',
                        'message' => 'Please add questions to the database.'
                    ]);
                    exit;
                }
                
                $formattedQuestions = [];
                foreach ($questions as $q) {
                    $formattedQuestions[] = [
                        'id' => $q['id'],
                        'question' => $q['question'],
                        'options' => [
                            $q['option_a'],
                            $q['option_b'],
                            $q['option_c'],
                            $q['option_d']
                        ],
                        'correct_answer' => $q['correct_answer'],
                        'difficulty' => $q['difficulty'],
                        'hint' => $q['hint'],
                        'description' => $q['description'],
                        'category' => $q['category']
                    ];
                }
                
                echo json_encode(['success' => true, 'questions' => $formattedQuestions, 'count' => count($formattedQuestions)]);
            } catch(PDOException $e) {
                echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
            }
            break;
            
        case 'save_session':
            $data = json_decode(file_get_contents('php://input'), true);
            
            $pdo->beginTransaction();
            
            $stmt = $pdo->prepare("INSERT INTO quiz_sessions (user_id, difficulty, total_questions, correct_answers, wrong_answers, points_earned, average_time) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $user['id'],
                $data['difficulty'],
                $data['total_questions'],
                $data['correct_answers'],
                $data['wrong_answers'],
                $data['points_earned'],
                $data['average_time']
            ]);
            
            $session_id = $pdo->lastInsertId();
            
            $stmt = $pdo->prepare("UPDATE users SET total_points = total_points + ?, total_correct = total_correct + ?, total_wrong = total_wrong + ?, total_quizzes = total_quizzes + 1 WHERE id = ?");
            $stmt->execute([
                $data['points_earned'],
                $data['correct_answers'],
                $data['wrong_answers'],
                $user['id']
            ]);
            
            if (isset($data['answers']) && is_array($data['answers'])) {
                foreach ($data['answers'] as $answer) {
                    $stmt = $pdo->prepare("INSERT INTO user_answers (user_id, question_id, session_id, selected_option, is_correct, time_taken, hint_used) VALUES (?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([
                        $user['id'],
                        $answer['question_id'],
                        $session_id,
                        $answer['selected_option'],
                        $answer['is_correct'] ? 1 : 0,
                        $answer['time_taken'],
                        $answer['hint_used'] ? 1 : 0
                    ]);
                }
            }
            
            $pdo->commit();
            
            echo json_encode(['success' => true, 'session_id' => $session_id]);
            break;
            
        case 'get_user_stats':
            $stmt = $pdo->prepare("SELECT * FROM leaderboard WHERE id = ?");
            $stmt->execute([$user['id']]);
            $stats = $stmt->fetch();
            
            if (!$stats) {
                $stats = [
                    'username' => $user['username'],
                    'total_points' => 0,
                    'total_correct' => 0,
                    'total_wrong' => 0,
                    'total_attempts' => 0,
                    'accuracy' => 0,
                    'total_quizzes' => 0,
                    'last_login' => $user['last_login']
                ];
            }
            
            echo json_encode(['success' => true, 'stats' => $stats]);
            break;
            
        case 'get_quiz_history':
            try {
                $limit = min(intval($_GET['limit'] ?? 20), 50);
                $sql = "SELECT * FROM quiz_sessions WHERE user_id = " . intval($user['id']) . " ORDER BY completed_at DESC LIMIT " . intval($limit);
                $stmt = $pdo->query($sql);
                $history = $stmt->fetchAll();
                echo json_encode(['success' => true, 'history' => $history, 'count' => count($history)]);
            } catch(PDOException $e) {
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
            break;
            
        default:
            echo json_encode(['error' => 'Invalid action']);
            break;
    }
    exit;
}

// ---------- HANDLE LOGIN/REGISTER ----------
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['login'])) {
        if (loginUser($pdo, $_POST['username'], $_POST['password'])) {
            header('Location: quiz.php');
            exit;
        } else {
            $error = 'Invalid username or password';
        }
    } elseif (isset($_POST['register'])) {
        if ($_POST['password'] !== $_POST['confirm_password']) {
            $error = 'Passwords do not match';
        } else {
            $result = registerUser($pdo, $_POST['username'], $_POST['email'], $_POST['password']);
            if ($result['success']) {
                loginUser($pdo, $_POST['username'], $_POST['password']);
                header('Location: quiz.php');
                exit;
            } else {
                $error = $result['message'];
            }
        }
    } elseif (isset($_POST['logout'])) {
        session_destroy();
        header('Location: quiz.php');
        exit;
    }
}

$currentUser = isLoggedIn() ? getCurrentUser($pdo) : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>ProQuiz · Ultimate</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" />
    <style>
        /* ===== ALL STYLES ===== */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }

        body {
            background: #eef4f9;
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 16px;
            position: relative;
        }

        .celebration-container {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            pointer-events: none;
            z-index: 9999;
            overflow: hidden;
        }
        .confetti {
            position: absolute;
            width: 10px;
            height: 10px;
            border-radius: 2px;
            animation: confettiFall linear forwards;
        }
        @keyframes confettiFall {
            0% { transform: translateY(-10vh) rotate(0deg); opacity: 1; }
            100% { transform: translateY(110vh) rotate(720deg); opacity: 0; }
        }

        .app-wrapper {
            max-width: 1100px;
            width: 100%;
            background: #ffffff;
            border-radius: 48px;
            box-shadow: 0 30px 60px -20px rgba(0, 20, 30, 0.3);
            padding: 24px 28px 28px;
            transition: all 0.2s;
            border: 1px solid rgba(255, 255, 255, 0.5);
            position: relative;
            z-index: 1;
        }

        /* LOGIN OVERLAY */
        .login-overlay {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 50px 30px 40px;
            gap: 28px;
            background: linear-gradient(135deg, #f8fbfd 0%, #e6f0f5 100%);
            border-radius: 40px;
            position: relative;
            overflow: hidden;
            min-height: 480px;
        }
        .login-overlay::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -30%;
            width: 600px;
            height: 600px;
            background: radial-gradient(circle, rgba(29, 107, 122, 0.08) 0%, transparent 70%);
            border-radius: 50%;
            pointer-events: none;
        }
        .login-overlay::after {
            content: '';
            position: absolute;
            bottom: -40%;
            left: -20%;
            width: 500px;
            height: 500px;
            background: radial-gradient(circle, rgba(29, 107, 122, 0.06) 0%, transparent 70%);
            border-radius: 50%;
            pointer-events: none;
        }
        .login-icon-wrapper {
            position: relative;
            z-index: 2;
            background: linear-gradient(135deg, #1d6b7a, #3ba6b9);
            width: 100px;
            height: 100px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 20px 40px -10px rgba(29, 107, 122, 0.4);
            animation: float 3s ease-in-out infinite;
        }
        @keyframes float {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-10px); }
        }
        .login-icon-wrapper i {
            font-size: 3.5rem;
            color: white;
        }
        .login-overlay h2 {
            color: #0b3343;
            font-size: 2.2rem;
            font-weight: 700;
            position: relative;
            z-index: 2;
            letter-spacing: -0.5px;
        }
        .login-overlay h2 span {
            background: linear-gradient(135deg, #1d6b7a, #3ba6b9);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        .login-subtitle {
            color: #4a7a8a;
            font-size: 1rem;
            position: relative;
            z-index: 2;
            margin-top: -8px;
        }
        .login-error {
            color: #e74c3c;
            font-size: 0.9rem;
            text-align: center;
            background: #fde8e5;
            padding: 8px 16px;
            border-radius: 60px;
            width: 100%;
            max-width: 400px;
        }
        .login-success {
            color: #27ae60;
            font-size: 0.9rem;
            text-align: center;
            background: #d5f5e3;
            padding: 8px 16px;
            border-radius: 60px;
            width: 100%;
            max-width: 400px;
        }
        .login-tabs {
            display: flex;
            gap: 10px;
            width: 100%;
            max-width: 400px;
        }
        .login-tabs button {
            flex: 1;
            padding: 10px;
            border: 2px solid #dcecf3;
            background: white;
            border-radius: 60px;
            font-weight: 600;
            cursor: pointer;
            transition: 0.3s;
            color: #5d8494;
        }
        .login-tabs button.active {
            background: #1d6b7a;
            color: white;
            border-color: #1d6b7a;
        }
        .login-tabs button:hover:not(.active) {
            background: #f0f7fc;
        }
        .login-box {
            display: flex;
            flex-direction: column;
            gap: 12px;
            position: relative;
            z-index: 2;
            width: 100%;
            max-width: 400px;
        }
        .login-box input {
            padding: 14px 20px;
            border-radius: 60px;
            border: 2px solid #dcecf3;
            font-size: 1rem;
            outline: none;
            transition: 0.3s;
            background: white;
            color: #0a2f3d;
            font-weight: 500;
            width: 100%;
        }
        .login-box input:focus {
            border-color: #1d6b7a;
            box-shadow: 0 0 0 4px rgba(29, 107, 122, 0.15);
            transform: scale(1.02);
        }
        .login-box button {
            background: linear-gradient(135deg, #1d6b7a, #3ba6b9);
            border: none;
            color: white;
            padding: 14px 36px;
            border-radius: 60px;
            font-weight: 600;
            font-size: 1rem;
            cursor: pointer;
            transition: 0.3s;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            box-shadow: 0 8px 24px -8px rgba(29, 107, 122, 0.4);
        }
        .login-box button:hover {
            transform: translateY(-3px) scale(1.02);
            box-shadow: 0 12px 32px -8px rgba(29, 107, 122, 0.5);
        }
        .login-features {
            display: flex;
            gap: 30px;
            position: relative;
            z-index: 2;
            margin-top: 8px;
            flex-wrap: wrap;
            justify-content: center;
        }
        .login-features span {
            font-size: 0.85rem;
            color: #5d8494;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .login-features i {
            color: #3ba6b9;
        }
        .login-demo-hint {
            position: relative;
            z-index: 2;
            background: rgba(255, 255, 255, 0.7);
            backdrop-filter: blur(4px);
            padding: 6px 20px;
            border-radius: 60px;
            font-size: 0.8rem;
            color: #4a7a8a;
            border: 1px solid rgba(29, 107, 122, 0.15);
        }

        .hidden {
            display: none !important;
        }

        /* HEADER */
        .quiz-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 14px 10px;
            margin-bottom: 20px;
            padding-bottom: 14px;
            border-bottom: 2px solid #ecf3f8;
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 700;
            font-size: 1.7rem;
            color: #0b2b3a;
            letter-spacing: -0.5px;
        }
        .brand i {
            color: #1d6b7a;
            font-size: 2rem;
        }
        .brand small {
            font-weight: 400;
            font-size: 0.7rem;
            background: #d9e9f2;
            padding: 2px 14px;
            border-radius: 30px;
            color: #1a4a57;
            margin-left: 6px;
        }

        .user-area {
            display: flex;
            align-items: center;
            gap: 14px;
            background: #f2f8fc;
            padding: 5px 14px 5px 18px;
            border-radius: 60px;
            border: 1px solid #ddecf3;
            transition: 0.2s;
        }
        .user-area:hover {
            border-color: #b8d4e0;
        }
        .user-area i {
            color: #1f6d7c;
            font-size: 1.1rem;
        }
        .user-area .user-name {
            font-weight: 500;
            color: #123845;
            font-size: 0.95rem;
        }
        .btn-logout {
            background: transparent;
            border: none;
            color: #a0bcc9;
            cursor: pointer;
            font-size: 0.95rem;
            transition: 0.2s;
            padding: 4px 8px;
            border-radius: 30px;
        }
        .btn-logout:hover {
            color: #c0392b;
            background: #fde8e5;
        }

        /* SETUP PANEL */
        .setup-panel {
            background: linear-gradient(135deg, #f6fbfe 0%, #ecf5fa 100%);
            border-radius: 32px;
            padding: 26px 28px;
            border: 1px solid #dfeef5;
            margin-bottom: 22px;
        }
        .setup-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 18px 30px;
            align-items: center;
        }
        .setup-item {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }
        .setup-item label {
            font-weight: 600;
            color: #1e4e5e;
            font-size: 0.9rem;
        }
        .setup-item select {
            padding: 10px 18px;
            border-radius: 60px;
            border: 2px solid #dcecf3;
            background: white;
            font-weight: 500;
            color: #0a2f3d;
            outline: none;
            transition: 0.2s;
            font-size: 0.9rem;
            min-width: 120px;
            cursor: pointer;
        }
        .setup-item select:focus {
            border-color: #1d6b7a;
            box-shadow: 0 0 0 4px #1d6b7a30;
        }
        .btn-start {
            background: #1d6b7a;
            border: none;
            color: white;
            padding: 11px 36px;
            border-radius: 60px;
            font-weight: 600;
            font-size: 0.95rem;
            cursor: pointer;
            transition: 0.2s;
            box-shadow: 0 8px 18px -8px #1d6b7a80;
            margin-left: auto;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .btn-start:hover {
            background: #135663;
            transform: translateY(-2px);
            box-shadow: 0 12px 24px -10px #0d3f4b;
        }

        /* DASHBOARD */
        .dashboard {
            display: flex;
            flex-wrap: wrap;
            gap: 10px 16px;
            background: linear-gradient(135deg, #f6fbfe 0%, #ecf5fa 100%);
            border-radius: 28px;
            padding: 14px 22px;
            margin-bottom: 24px;
            border: 1px solid #dfeef5;
            align-items: center;
        }
        .stat-item {
            display: flex;
            align-items: center;
            gap: 6px;
            background: white;
            padding: 3px 14px 3px 12px;
            border-radius: 40px;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.02);
        }
        .stat-item i {
            color: #2b7f8f;
            font-size: 1rem;
            width: 20px;
            text-align: center;
        }
        .stat-item .label {
            font-size: 0.7rem;
            color: #3d6f7e;
            font-weight: 500;
            letter-spacing: 0.2px;
        }
        .stat-item .value {
            font-weight: 700;
            font-size: 1rem;
            color: #0a3343;
            margin-left: 2px;
        }
        .stat-divider {
            width: 1px;
            height: 24px;
            background: #cde0e9;
        }

        .points-display {
            display: flex;
            align-items: center;
            gap: 8px;
            background: linear-gradient(135deg, #f5c542, #f7d76a);
            padding: 4px 18px 4px 14px;
            border-radius: 60px;
            font-weight: 700;
            color: #1a2b33;
            box-shadow: 0 4px 12px -4px rgba(245, 197, 66, 0.4);
            margin-left: auto;
        }
        .points-display i {
            font-size: 1.1rem;
        }
        .points-display .points-value {
            font-size: 1.1rem;
            min-width: 30px;
            text-align: center;
        }

        /* HISTORY SECTION */
        .history-section {
            background: linear-gradient(135deg, #f6fbfe 0%, #ecf5fa 100%);
            border-radius: 28px;
            padding: 20px 24px;
            margin-bottom: 24px;
            border: 1px solid #dfeef5;
        }
        .history-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
            flex-wrap: wrap;
            gap: 10px;
        }
        .history-header h3 {
            color: #1a4a57;
            font-size: 1.1rem;
        }
        .history-header h3 i {
            color: #2b7f8f;
            margin-right: 8px;
        }
        .history-toggle {
            background: #1d6b7a;
            border: none;
            color: white;
            padding: 6px 18px;
            border-radius: 60px;
            font-weight: 500;
            font-size: 0.85rem;
            cursor: pointer;
            transition: 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .history-toggle:hover {
            background: #135663;
            transform: translateY(-2px);
        }
        .history-list {
            max-height: 400px;
            overflow-y: auto;
            display: none;
        }
        .history-list.show {
            display: block;
        }
        .history-item {
            background: white;
            border-radius: 16px;
            padding: 12px 18px;
            margin-bottom: 8px;
            border: 1px solid #e6f0f5;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
            transition: 0.2s;
        }
        .history-item:hover {
            border-color: #8bbccb;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }
        .history-item .h-date {
            font-size: 0.75rem;
            color: #5d8494;
        }
        .history-item .h-score {
            font-weight: 700;
            color: #0a3343;
            font-size: 1rem;
        }
        .history-item .h-detail {
            font-size: 0.8rem;
            color: #3d6f7e;
        }
        .history-item .h-badge {
            padding: 2px 12px;
            border-radius: 30px;
            font-size: 0.7rem;
            font-weight: 600;
        }
        .history-item .h-badge.good {
            background: #c8f0da;
            color: #0b5e37;
        }
        .history-item .h-badge.average {
            background: #fef9e7;
            color: #5a3e1a;
        }
        .history-item .h-badge.poor {
            background: #fce1e3;
            color: #a12432;
        }
        .history-empty {
            text-align: center;
            color: #5d8494;
            padding: 20px;
            font-size: 0.9rem;
        }
        .history-empty i {
            font-size: 2rem;
            color: #cde0e9;
            display: block;
            margin-bottom: 8px;
        }
        .history-loading {
            text-align: center;
            color: #5d8494;
            padding: 20px;
        }
        .history-loading i {
            font-size: 1.5rem;
            color: #2b7f8f;
            display: block;
            margin-bottom: 8px;
            animation: spin 1s linear infinite;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* PROGRESS BAR */
        .progress-container {
            margin-bottom: 20px;
        }
        .progress-bar-bg {
            width: 100%;
            height: 8px;
            background: #e6f0f5;
            border-radius: 10px;
            overflow: hidden;
            position: relative;
        }
        .progress-bar-fill {
            height: 100%;
            background: linear-gradient(90deg, #1d6b7a, #3ba6b9);
            border-radius: 10px;
            transition: width 0.5s ease;
            width: 0%;
        }
        .progress-text {
            display: flex;
            justify-content: space-between;
            font-size: 0.8rem;
            color: #3d6f7e;
            margin-top: 4px;
        }

        /* QUIZ AREA */
        .question-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
            flex-wrap: wrap;
            gap: 8px;
        }
        .q-counter {
            background: #e3f0f6;
            padding: 4px 16px;
            border-radius: 60px;
            font-weight: 600;
            color: #154c5c;
            font-size: 0.9rem;
        }
        .timer-badge {
            background: #153e4b;
            color: white;
            padding: 4px 14px 4px 12px;
            border-radius: 60px;
            display: flex;
            align-items: center;
            gap: 6px;
            font-weight: 500;
            font-size: 0.9rem;
        }
        .timer-badge i {
            color: #f5c542;
        }
        .timer-badge span {
            background: #f5c542;
            color: #1a2b33;
            padding: 0 10px;
            border-radius: 30px;
            font-weight: 700;
            min-width: 34px;
            text-align: center;
        }

        .question-text {
            font-size: 1.4rem;
            font-weight: 500;
            color: #0a2938;
            margin-bottom: 16px;
            padding-left: 4px;
            line-height: 1.4;
        }

        .hint-description-container {
            display: flex;
            gap: 10px;
            margin-bottom: 18px;
            flex-wrap: wrap;
            align-items: center;
        }
        .btn-hint {
            background: linear-gradient(135deg, #f5c542, #f7d76a);
            border: none;
            padding: 6px 18px;
            border-radius: 60px;
            font-weight: 600;
            font-size: 0.8rem;
            color: #1a2b33;
            cursor: pointer;
            transition: 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            box-shadow: 0 4px 12px -4px rgba(245, 197, 66, 0.4);
        }
        .btn-hint:hover:not(:disabled) {
            transform: translateY(-2px) scale(1.03);
            box-shadow: 0 6px 16px -4px rgba(245, 197, 66, 0.5);
        }
        .btn-hint:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none;
        }
        .hint-text {
            background: #fef9e7;
            border-left: 4px solid #f5c542;
            padding: 8px 16px;
            border-radius: 12px;
            font-size: 0.9rem;
            color: #4a3e1a;
            display: none;
            flex: 1;
            min-width: 120px;
        }
        .hint-text.show {
            display: block;
        }
        .hint-text i {
            color: #f5c542;
            margin-right: 8px;
        }

        .comedy-message {
            background: #fef0e6;
            border-left: 4px solid #f39c12;
            padding: 12px 18px;
            border-radius: 12px;
            font-size: 1rem;
            color: #5a3e1a;
            display: none;
            flex: 1;
            min-width: 120px;
            animation: shake 0.6s ease;
            font-weight: 500;
            line-height: 1.5;
        }
        .comedy-message.show {
            display: block;
        }
        .comedy-message i {
            color: #f39c12;
            margin-right: 10px;
            font-size: 1.3rem;
        }
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            10% { transform: translateX(-12px); }
            30% { transform: translateX(12px); }
            50% { transform: translateX(-8px); }
            70% { transform: translateX(8px); }
            90% { transform: translateX(-4px); }
        }

        .answer-description {
            background: #f0f7fc;
            border-left: 4px solid #e74c3c;
            padding: 10px 16px;
            border-radius: 12px;
            margin-bottom: 16px;
            font-size: 0.95rem;
            color: #154c5c;
            display: none;
        }
        .answer-description.show {
            display: block;
        }
        .answer-description i {
            color: #e74c3c;
            margin-right: 8px;
        }

        .options-grid {
            display: flex;
            flex-direction: column;
            gap: 10px;
            margin-bottom: 26px;
        }
        .option {
            background: #f8fcfd;
            border: 2px solid #ddecf3;
            border-radius: 60px;
            padding: 13px 20px;
            font-weight: 500;
            color: #11323f;
            cursor: pointer;
            transition: 0.12s;
            display: flex;
            align-items: center;
            gap: 14px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.02);
        }
        .option:hover:not(.disabled):not(.locked) {
            background: #e7f3f8;
            border-color: #8bbccb;
            transform: scale(1.01);
        }
        .option .opt-letter {
            background: #1a5b6b;
            color: white;
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            font-weight: 600;
            flex-shrink: 0;
            font-size: 0.9rem;
        }
        .option.selected {
            background: #d3eaf2;
            border-color: #267b8b;
            box-shadow: 0 0 0 3px #267b8b40;
        }
        .option.correct-reveal {
            background: #c8f0da;
            border-color: #1d7a4f;
            box-shadow: 0 0 0 3px #1d7a4f55;
        }
        .option.wrong-reveal {
            background: #fce1e3;
            border-color: #b02a3a;
            box-shadow: 0 0 0 3px #b02a3a55;
        }
        .option.disabled,
        .option.locked {
            cursor: default;
            opacity: 0.8;
        }
        .option.locked:hover {
            transform: none;
            background: #f8fcfd;
        }
        .option.locked.selected {
            background: #d3eaf2;
        }

        .action-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }
        .btn {
            border: none;
            padding: 9px 24px;
            border-radius: 60px;
            font-weight: 600;
            font-size: 0.9rem;
            background: white;
            border: 1px solid #cfe0e8;
            color: #1f5a6b;
            cursor: pointer;
            transition: 0.15s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .btn-primary {
            background: #1d6b7a;
            border: 1px solid #1d6b7a;
            color: white;
            box-shadow: 0 8px 18px -10px #1d6b7a80;
        }
        .btn-primary:hover:not(:disabled) {
            background: #135663;
            transform: translateY(-2px);
            box-shadow: 0 12px 24px -10px #0d3f4b;
        }
        .btn-primary:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none;
        }
        .btn-secondary {
            background: #eef6fa;
            border-color: #caddf0;
        }
        .btn-secondary:hover:not(:disabled) {
            background: #dcecf4;
        }

        .result-panel {
            margin-top: 8px;
        }
        .result-summary {
            background: linear-gradient(135deg, #ebf5fa 0%, #dcecf4 100%);
            border-radius: 30px;
            padding: 20px 24px;
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            align-items: center;
            border: 1px solid #c9e0ec;
            margin-bottom: 24px;
            gap: 16px;
        }
        .score-big {
            font-size: 2.8rem;
            font-weight: 700;
            color: #0b3343;
            background: white;
            padding: 0 24px;
            border-radius: 60px;
            line-height: 1.4;
        }
        .result-meta {
            display: flex;
            gap: 14px;
            flex-wrap: wrap;
        }
        .result-meta .tag {
            background: white;
            padding: 5px 18px;
            border-radius: 40px;
            font-weight: 500;
            color: #154c5c;
            font-size: 0.9rem;
        }

        .result-detail-grid {
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin-top: 10px;
            max-height: 400px;
            overflow-y: auto;
            padding-right: 4px;
        }
        .result-item {
            display: flex;
            align-items: center;
            gap: 12px;
            background: #fafdff;
            border-radius: 60px;
            padding: 7px 14px 7px 18px;
            border: 1px solid #e6f0f5;
            flex-wrap: wrap;
            transition: 0.2s;
        }
        .result-item:hover {
            background: #f0f7fc;
        }
        .result-item .qnum {
            font-weight: 600;
            color: #1f596b;
            min-width: 36px;
            font-size: 0.9rem;
        }
        .result-item .qtext {
            flex: 1;
            font-weight: 450;
            color: #113542;
            min-width: 100px;
            font-size: 0.9rem;
        }
        .result-item .icon-result {
            font-size: 1.2rem;
            width: 28px;
            text-align: center;
        }
        .result-item .correct-badge {
            background: #c0e6d1;
            color: #0b5e37;
            padding: 0 12px;
            border-radius: 40px;
            font-weight: 600;
            font-size: 0.8rem;
        }
        .result-item .wrong-badge {
            background: #fad1d4;
            color: #a12432;
            padding: 0 12px;
            border-radius: 40px;
            font-weight: 600;
            font-size: 0.8rem;
        }

        .btn-restart {
            background: #1d6b7a;
            border: none;
            color: white;
            padding: 10px 32px;
            border-radius: 60px;
            font-weight: 600;
            font-size: 0.95rem;
            cursor: pointer;
            transition: 0.2s;
            box-shadow: 0 6px 14px -6px #1d6b7a80;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .btn-restart:hover {
            background: #11525f;
            transform: translateY(-2px);
        }

        .footnote {
            text-align: right;
            margin-top: 16px;
            color: #5d8494;
            font-size: 0.75rem;
        }
        ::-webkit-scrollbar {
            width: 5px;
        }
        ::-webkit-scrollbar-track {
            background: #e6f0f5;
            border-radius: 10px;
        }
        ::-webkit-scrollbar-thumb {
            background: #8bbccb;
            border-radius: 10px;
        }

        @media (max-width: 820px) {
            .app-wrapper { padding: 18px; }
            .setup-grid { flex-direction: column; align-items: stretch; }
            .btn-start { margin-left: 0; width: 100%; justify-content: center; }
            .dashboard { gap: 8px; padding: 12px 16px; }
            .stat-divider { display: none; }
            .result-summary { flex-direction: column; align-items: flex-start; gap: 12px; }
            .score-big { font-size: 2.4rem; padding: 0 18px; }
            .question-text { font-size: 1.2rem; }
            .option { padding: 11px 16px; }
            .brand { font-size: 1.4rem; }
            .login-overlay { padding: 30px 20px; min-height: 400px; }
            .login-icon-wrapper { width: 80px; height: 80px; }
            .login-icon-wrapper i { font-size: 2.8rem; }
            .login-overlay h2 { font-size: 1.8rem; }
            .login-box button { padding: 12px 28px; font-size: 0.9rem; }
            .login-features { gap: 16px; }
            .hint-description-container { flex-direction: column; align-items: stretch; }
            .points-display { margin-left: 0; width: 100%; justify-content: center; }
            .history-item { flex-direction: column; align-items: stretch; }
        }
        @media (max-width: 480px) {
            .app-wrapper { padding: 12px; border-radius: 32px; }
            .brand { font-size: 1.2rem; }
            .brand small { font-size: 0.6rem; padding: 1px 10px; }
            .user-area { padding: 4px 10px 4px 14px; gap: 8px; }
            .user-area .user-name { font-size: 0.8rem; }
            .login-box input { padding: 12px 16px; font-size: 0.9rem; min-width: 140px; }
            .login-box button { padding: 12px 24px; font-size: 0.85rem; width: 100%; justify-content: center; }
            .setup-item select { min-width: 100px; padding: 8px 14px; }
            .btn-start { padding: 10px 20px; font-size: 0.9rem; }
            .question-text { font-size: 1.05rem; }
            .option { padding: 10px 14px; font-size: 0.9rem; }
            .option .opt-letter { width: 26px; height: 26px; font-size: 0.8rem; }
            .result-item { padding: 6px 12px; }
            .result-item .qtext { font-size: 0.8rem; }
            .login-overlay { padding: 24px 16px; min-height: 360px; }
            .login-icon-wrapper { width: 70px; height: 70px; }
            .login-icon-wrapper i { font-size: 2.4rem; }
            .login-overlay h2 { font-size: 1.5rem; }
            .login-subtitle { font-size: 0.85rem; }
            .login-features span { font-size: 0.75rem; }
            .hint-description-container { flex-direction: column; align-items: stretch; }
            .btn-hint { width: 100%; justify-content: center; }
            .points-display { margin-left: 0; width: 100%; justify-content: center; }
            .history-item { flex-direction: column; align-items: stretch; }
        }
    </style>
</head>
<body>

<!-- Celebration Container -->
<div class="celebration-container" id="celebrationContainer"></div>

<div class="app-wrapper" id="appWrapper">

<?php if (!isLoggedIn()): ?>
    <!-- ========== LOGIN OVERLAY ========== -->
    <div id="loginView" class="login-overlay">
        <div class="login-icon-wrapper">
            <i class="fas fa-graduation-cap"></i>
        </div>
        <h2>Welcome to <span>ProQuiz</span></h2>
        <p class="login-subtitle">Master your knowledge with interactive quizzes</p>
        
        <?php if (isset($error) && !empty($error)): ?>
            <div class="login-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <?php if (isset($success) && !empty($success)): ?>
            <div class="login-success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>
        
        <div class="login-tabs">
            <button class="active" onclick="showTab('login')">Login</button>
            <button onclick="showTab('register')">Register</button>
        </div>
        
        <!-- Login Form -->
        <form method="POST" class="login-box" id="loginForm">
            <input type="text" name="username" placeholder="Username" required />
            <input type="password" name="password" placeholder="Password" required />
            <button type="submit" name="login">Login <i class="fas fa-arrow-right"></i></button>
        </form>
        
        <!-- Register Form -->
        <form method="POST" class="login-box hidden" id="registerForm">
            <input type="text" name="username" placeholder="Choose Username" required />
            <input type="email" name="email" placeholder="Email (optional)" />
            <input type="password" name="password" placeholder="Password" required />
            <input type="password" name="confirm_password" placeholder="Confirm Password" required />
            <button type="submit" name="register">Create Account <i class="fas fa-user-plus"></i></button>
        </form>
        
        <div class="login-features">
            <span><i class="fas fa-layer-group"></i> Multiple Difficulties</span>
            <span><i class="fas fa-random"></i> Shuffled Questions</span>
            <span><i class="fas fa-trophy"></i> Track Progress</span>
        </div>
        <div class="login-demo-hint">
            <i class="fas fa-info-circle"></i> Demo: demo_user / admin123
        </div>
    </div>

    <script>
        function showTab(tab) {
            document.getElementById('loginForm').classList.toggle('hidden', tab !== 'login');
            document.getElementById('registerForm').classList.toggle('hidden', tab !== 'register');
            document.querySelectorAll('.login-tabs button').forEach(b => b.classList.remove('active'));
            if (tab === 'login') {
                document.querySelector('.login-tabs button:first-child').classList.add('active');
            } else {
                document.querySelector('.login-tabs button:last-child').classList.add('active');
            }
        }
    </script>

<?php else: ?>
    <!-- ========== MAIN QUIZ VIEW ========== -->
    <div id="quizView">

        <!-- Header -->
        <div class="quiz-header">
            <div class="brand">
                <i class="fas fa-brain"></i> ProQuiz
                <small>ultimate</small>
            </div>
            <div class="user-area">
                <i class="fas fa-user-circle"></i>
                <span class="user-name"><?php echo htmlspecialchars($currentUser['username']); ?></span>
                <form method="POST" style="display:inline;">
                    <button type="submit" name="logout" class="btn-logout"><i class="fas fa-sign-out-alt"></i></button>
                </form>
            </div>
        </div>

        <!-- Setup Panel -->
        <div class="setup-panel" id="setupPanel">
            <div class="setup-grid">
                <div class="setup-item">
                    <label><i class="fas fa-layer-group"></i> Difficulty</label>
                    <select id="difficultySelect">
                        <option value="all">All Levels</option>
                        <option value="easy">Easy</option>
                        <option value="medium" selected>Medium</option>
                        <option value="hard">Hard</option>
                    </select>
                </div>
                <div class="setup-item">
                    <label><i class="fas fa-list-ol"></i> Questions</label>
                    <select id="countSelect">
                        <option value="5">5</option>
                        <option value="8">8</option>
                        <option value="10">10</option>
                        <option value="12">12</option>
                        <option value="15">15</option>
                        <option value="20">20</option>
                    </select>
                </div>
                <div class="setup-item">
                    <label><i class="fas fa-random"></i> Shuffle</label>
                    <select id="shuffleSelect">
                        <option value="true" selected>Yes</option>
                        <option value="false">No</option>
                    </select>
                </div>
                <button class="btn-start" id="startQuizBtn"><i class="fas fa-play"></i> Start Quiz</button>
            </div>
        </div>

        <!-- Dashboard -->
        <div class="dashboard" id="dashboard">
            <div class="stat-item"><i class="fas fa-list"></i><span class="label">Total</span><span class="value" id="totalQ">0</span></div>
            <div class="stat-divider"></div>
            <div class="stat-item"><i class="fas fa-check-circle"></i><span class="label">Correct</span><span class="value" id="correctCount">0</span></div>
            <div class="stat-divider"></div>
            <div class="stat-item"><i class="fas fa-times-circle"></i><span class="label">Wrong</span><span class="value" id="wrongCount">0</span></div>
            <div class="stat-divider"></div>
            <div class="stat-item"><i class="fas fa-percent"></i><span class="label">Accuracy</span><span class="value" id="accuracy">0%</span></div>
            <div class="stat-divider"></div>
            <div class="stat-item"><i class="fas fa-lock"></i><span class="label">Locked</span><span class="value" id="lockedCount">0</span></div>
            <div class="stat-divider"></div>
            <div class="stat-item"><i class="fas fa-question-circle"></i><span class="label">Unanswered</span><span class="value" id="unansweredCount">0</span></div>
            <div class="stat-divider"></div>
            <div class="stat-item"><i class="fas fa-clock"></i><span class="label">Avg Time</span><span class="value" id="avgTime">0s</span></div>
            <div class="stat-divider"></div>
            <div class="points-display">
                <i class="fas fa-star"></i>
                <span class="points-value" id="pointsDisplay">0</span>
                <span style="font-size:0.7rem; font-weight:400;">pts</span>
            </div>
        </div>

        <!-- History Section -->
        <div class="history-section">
            <div class="history-header">
                <h3><i class="fas fa-history"></i> Your Quiz History</h3>
                <button class="history-toggle" id="historyToggle">
                    <i class="fas fa-chevron-down"></i> Show History
                </button>
            </div>
            <div class="history-list" id="historyList">
                <div class="history-loading">
                    <i class="fas fa-spinner"></i>
                    Loading history...
                </div>
            </div>
        </div>

        <!-- Progress Bar -->
        <div class="progress-container">
            <div class="progress-bar-bg">
                <div class="progress-bar-fill" id="progressFill"></div>
            </div>
            <div class="progress-text">
                <span id="progressLabel">0% complete</span>
                <span id="progressUnanswered">Unanswered: 0</span>
            </div>
        </div>

        <!-- Quiz Area -->
        <div id="quizArea" class="hidden">
            <div class="question-header">
                <div class="q-counter" id="questionCounter">Q1 / 8</div>
                <div class="timer-badge"><i class="fas fa-hourglass-half"></i> <span id="timerDisplay">30</span>s</div>
            </div>
            <div class="question-text" id="questionText">Loading...</div>

            <!-- Hint & Description Container -->
            <div class="hint-description-container">
                <button class="btn-hint" id="hintBtn"><i class="fas fa-lightbulb"></i> Hint (15 pts)</button>
                <div class="hint-text" id="hintText"><i class="fas fa-info-circle"></i> <span id="hintContent">Hint will appear here</span></div>
                <div class="comedy-message" id="comedyMessage"><i class="fas fa-laugh-squint"></i> <span id="comedyText">😂</span></div>
            </div>

            <!-- Answer Description -->
            <div class="answer-description" id="answerDescription">
                <i class="fas fa-exclamation-circle"></i> <span id="descriptionText">Description will appear here.</span>
            </div>

            <div class="options-grid" id="optionsContainer"></div>
            <div class="action-bar">
                <button class="btn btn-secondary" id="prevBtn" disabled><i class="fas fa-arrow-left"></i> Prev</button>
                <button class="btn btn-primary" id="nextBtn">Next <i class="fas fa-arrow-right"></i></button>
            </div>
        </div>

        <!-- Result Panel -->
        <div id="resultPanel" class="result-panel hidden">
            <div class="result-summary">
                <div class="score-big" id="finalScore">0/8</div>
                <div class="result-meta">
                    <span class="tag" id="resultBadge">🌟 Excellent</span>
                    <span class="tag"><i class="far fa-clock"></i> <span id="resultTime">0s</span></span>
                    <span class="tag"><i class="fas fa-star"></i> <span id="resultPoints">0</span> pts</span>
                </div>
            </div>
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; flex-wrap: wrap; gap: 10px;">
                <span style="font-weight:600; color:#124857;"><i class="fas fa-list-ul"></i> Detailed review</span>
                <button class="btn-restart" id="restartBtn"><i class="fas fa-redo"></i> Restart</button>
            </div>
            <div class="result-detail-grid" id="resultDetailGrid"></div>
        </div>

        <div class="footnote"><i class="fas fa-shield-alt"></i> locked answers · shuffled options · timer per question · +15 pts for correct!</div>
    </div>
<?php endif; ?>

</div>

<?php if (isLoggedIn()): ?>
<script>
// ============================================
// FULL QUIZ JAVASCRIPT ENGINE
// ============================================

const SCRIPT_NAME = 'quiz.php';

// ---------- COMEDY MESSAGES ----------
const comedyMessages = [
    "😂 अरे भाई! 15 पॉइंट्स नहीं हैं तो हिंट कैसे लोगे? पहले सवाल तो सही करो!",
    "😅 ओहो! पॉइंट्स कम पड़ गए? लगता है आज किसी का दिन अच्छा नहीं है!",
    "🤣 'हिंट लेना है' - आपके पॉइंट्स: 'हम कहाँ जाएं?' पहले कमाओ फिर मांगो!",
    "😭 15 पॉइंट्स? आपके पास तो 0 हैं! ये तो 'चाय-पानी' वाली बात हो गई!",
    "🧠 हिंट की कीमत 15 पॉइंट्स है। आपके दिमाग की कीमत? फिलहाल 0!",
    "💀 'हिंट चाहिए' - आपके पॉइंट्स: 'हम सो रहे हैं'। पहले जगाओ हमें!",
    "🤡 आप: 'हिंट दो!' पॉइंट्स: 'हमें क्यों दें?' पहले कुछ सही करो फिर आना!",
    "😆 15 पॉइंट्स नहीं? तो फिर 'गूगल' कर लो! वो भी फ्री है!",
    "🤪 आपके पॉइंट्स इतने कम हैं कि हिंट भी आपको नहीं पहचान रहा!",
    "🥲 'हिंट माँग रहे हो?' - आपके पॉइंट्स: 'हम तो नए हैं यहाँ!'"
];

// ---------- STATE ----------
let questions = [];
let currentIndex = 0;
let selectedAnswers = [];
let lockedAnswers = [];
let hintUsed = [];
let points = 0;
let quizCompleted = false;
let timerInterval = null;
let timeLeft = 30;
const TIME_LIMIT = 30;
let quizStarted = false;
let questionStartTime = 0;
let timePerQuestion = [];
const HINT_COST = 15;
const POINTS_PER_CORRECT = 15;

// ---------- DOM REFS ----------
const setupPanel = document.getElementById('setupPanel');
const quizArea = document.getElementById('quizArea');
const resultPanel = document.getElementById('resultPanel');
const startQuizBtn = document.getElementById('startQuizBtn');
const difficultySelect = document.getElementById('difficultySelect');
const countSelect = document.getElementById('countSelect');
const shuffleSelect = document.getElementById('shuffleSelect');

const totalQ = document.getElementById('totalQ');
const correctCount = document.getElementById('correctCount');
const wrongCount = document.getElementById('wrongCount');
const accuracy = document.getElementById('accuracy');
const lockedCount = document.getElementById('lockedCount');
const unansweredCount = document.getElementById('unansweredCount');
const avgTime = document.getElementById('avgTime');
const pointsDisplay = document.getElementById('pointsDisplay');

const questionCounter = document.getElementById('questionCounter');
const timerDisplay = document.getElementById('timerDisplay');
const questionText = document.getElementById('questionText');
const optionsContainer = document.getElementById('optionsContainer');
const prevBtn = document.getElementById('prevBtn');
const nextBtn = document.getElementById('nextBtn');

const progressFill = document.getElementById('progressFill');
const progressLabel = document.getElementById('progressLabel');
const progressUnanswered = document.getElementById('progressUnanswered');

const answerDescription = document.getElementById('answerDescription');
const descriptionText = document.getElementById('descriptionText');
const hintBtn = document.getElementById('hintBtn');
const hintText = document.getElementById('hintText');
const hintContent = document.getElementById('hintContent');
const comedyMessage = document.getElementById('comedyMessage');
const comedyText = document.getElementById('comedyText');

const finalScore = document.getElementById('finalScore');
const resultBadge = document.getElementById('resultBadge');
const resultTime = document.getElementById('resultTime');
const resultPoints = document.getElementById('resultPoints');
const resultDetailGrid = document.getElementById('resultDetailGrid');
const restartBtn = document.getElementById('restartBtn');
const celebrationContainer = document.getElementById('celebrationContainer');

// History elements
const historyList = document.getElementById('historyList');
const historyToggle = document.getElementById('historyToggle');

// ---------- API FUNCTIONS ----------
async function fetchQuestions(difficulty, limit) {
    const url = `${SCRIPT_NAME}?api=1&action=get_questions&difficulty=${difficulty}&limit=${limit}`;
    console.log('🔄 Fetching questions from:', url);
    
    const response = await fetch(url);
    console.log('📡 Response status:', response.status);
    
    if (!response.ok) {
        throw new Error(`HTTP error! status: ${response.status}`);
    }
    
    const data = await response.json();
    console.log('📦 Response data:', data);
    return data;
}

async function saveSession(data) {
    const response = await fetch(`${SCRIPT_NAME}?api=1&action=save_session`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(data)
    });
    return await response.json();
}

async function fetchQuizHistory() {
    try {
        const response = await fetch(`${SCRIPT_NAME}?api=1&action=get_quiz_history&limit=20`);
        const data = await response.json();
        console.log('📦 History data:', data);
        return data;
    } catch(e) {
        console.error('Error fetching history:', e);
        return null;
    }
}

// ---------- HELPERS ----------
function shuffleArray(arr) {
    for (let i = arr.length - 1; i > 0; i--) {
        const j = Math.floor(Math.random() * (i + 1));
        [arr[i], arr[j]] = [arr[j], arr[i]];
    }
    return arr;
}

function shuffleOptions(q) {
    const opts = q.options.map((text, idx) => ({ text, idx }));
    const shuffled = shuffleArray([...opts]);
    const newOptions = shuffled.map(item => item.text);
    const correctMap = { 'A': 0, 'B': 1, 'C': 2, 'D': 3 };
    const correctIdx = correctMap[q.correct_answer] || 0;
    const shuffledCorrect = shuffled.findIndex(item => item.idx === correctIdx);
    return { ...q, options: newOptions, correct: shuffledCorrect };
}

function stopTimer() {
    if (timerInterval) {
        clearInterval(timerInterval);
        timerInterval = null;
    }
}

function startTimer() {
    stopTimer();
    timeLeft = TIME_LIMIT;
    timerDisplay.textContent = timeLeft;
    questionStartTime = Date.now();
    timerInterval = setInterval(() => {
        timeLeft--;
        timerDisplay.textContent = timeLeft >= 0 ? timeLeft : 0;
        if (timeLeft <= 0) {
            stopTimer();
            if (!quizCompleted && quizStarted) handleTimeout();
        }
    }, 1000);
}

function handleTimeout() {
    if (quizCompleted || !quizStarted) return;
    if (selectedAnswers[currentIndex] !== null && !lockedAnswers[currentIndex]) {
        lockedAnswers[currentIndex] = true;
        const elapsed = (Date.now() - questionStartTime) / 1000;
        timePerQuestion[currentIndex] = Math.min(elapsed, TIME_LIMIT);
    }
    const isLast = (currentIndex === questions.length - 1);
    if (isLast) {
        finishQuiz();
    } else {
        goToQuestion(currentIndex + 1);
    }
}

function triggerCelebration() {
    const colors = ['#ff6b6b', '#feca57', '#48dbfb', '#ff9ff3', '#54a0ff', '#5f27cd', '#1dd1a1', '#f368e0'];
    for (let i = 0; i < 80; i++) {
        const confetti = document.createElement('div');
        confetti.className = 'confetti';
        confetti.style.left = Math.random() * 100 + '%';
        confetti.style.background = colors[Math.floor(Math.random() * colors.length)];
        confetti.style.width = (Math.random() * 8 + 4) + 'px';
        confetti.style.height = (Math.random() * 8 + 4) + 'px';
        confetti.style.animationDuration = (Math.random() * 2 + 2) + 's';
        confetti.style.animationDelay = (Math.random() * 2) + 's';
        celebrationContainer.appendChild(confetti);
        setTimeout(() => confetti.remove(), 4000);
    }
}

// ---------- HISTORY FUNCTIONS ----------
async function loadHistory() {
    // Show loading state
    historyList.innerHTML = `
        <div class="history-loading">
            <i class="fas fa-spinner"></i>
            Loading history...
        </div>
    `;

    const result = await fetchQuizHistory();
    
    if (!result) {
        historyList.innerHTML = `
            <div class="history-empty">
                <i class="fas fa-exclamation-circle"></i>
                Could not load history. Please try again.
            </div>
        `;
        return;
    }

    if (!result.success) {
        historyList.innerHTML = `
            <div class="history-empty">
                <i class="fas fa-exclamation-circle"></i>
                Error: ${result.error || 'Unknown error'}
            </div>
        `;
        return;
    }

    if (result.history.length === 0) {
        historyList.innerHTML = `
            <div class="history-empty">
                <i class="fas fa-inbox"></i>
                No quiz history yet. Complete a quiz to see your results here!
            </div>
        `;
        return;
    }

    let html = '';
    result.history.forEach(session => {
        const total = session.total_questions;
        const correct = session.correct_answers;
        const wrong = session.wrong_answers;
        const pct = Math.round((correct / total) * 100);
        
        let badgeClass = 'poor';
        let badgeText = '📖 Practice More';
        if (pct >= 70) { badgeClass = 'good'; badgeText = '🌟 Good Job'; }
        else if (pct >= 50) { badgeClass = 'average'; badgeText = '📚 Keep Going'; }
        
        const date = new Date(session.completed_at);
        const formattedDate = date.toLocaleDateString('en-IN', { 
            day: '2-digit', 
            month: 'short', 
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        });
        
        html += `
            <div class="history-item">
                <div>
                    <span class="h-score">${correct}/${total}</span>
                    <span class="h-detail"> • ${pct}% • ${session.difficulty}</span>
                    <span class="h-detail"> • ⭐ ${session.points_earned} pts</span>
                </div>
                <div>
                    <span class="h-badge ${badgeClass}">${badgeText}</span>
                    <span class="h-date">${formattedDate}</span>
                </div>
            </div>
        `;
    });
    historyList.innerHTML = html;
}

// History toggle
historyToggle.addEventListener('click', function() {
    const isOpen = historyList.classList.toggle('show');
    this.innerHTML = isOpen ? 
        '<i class="fas fa-chevron-up"></i> Hide History' : 
        '<i class="fas fa-chevron-down"></i> Show History';
});

// ---------- BUILD QUIZ ----------
async function buildQuiz() {
    const diff = difficultySelect.value;
    const count = parseInt(countSelect.value);
    const shouldShuffle = shuffleSelect.value === 'true';

    startQuizBtn.disabled = true;
    startQuizBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Loading...';

    try {
        const result = await fetchQuestions(diff, count);
        
        if (!result.success) {
            alert('Error: ' + (result.message || result.error || 'Unknown error'));
            startQuizBtn.disabled = false;
            startQuizBtn.innerHTML = '<i class="fas fa-play"></i> Start Quiz';
            return;
        }

        if (!result.questions || result.questions.length === 0) {
            alert('No questions found in the database!');
            startQuizBtn.disabled = false;
            startQuizBtn.innerHTML = '<i class="fas fa-play"></i> Start Quiz';
            return;
        }

        console.log('✅ Received ' + result.questions.length + ' questions (Requested: ' + count + ')');

        let rawQuestions = result.questions;
        let shuffled = shuffleArray([...rawQuestions]);
        let selected = shuffled.slice(0, Math.min(count, shuffled.length));

        questions = selected.map(q => shuffleOptions(q));

        if (shouldShuffle) {
            questions = shuffleArray(questions);
        }

        selectedAnswers = new Array(questions.length).fill(null);
        lockedAnswers = new Array(questions.length).fill(false);
        hintUsed = new Array(questions.length).fill(false);
        timePerQuestion = new Array(questions.length).fill(0);
        points = 0;
        currentIndex = 0;
        quizCompleted = false;
        quizStarted = true;
        
        setupPanel.classList.add('hidden');
        quizArea.classList.remove('hidden');
        resultPanel.classList.add('hidden');
        answerDescription.classList.remove('show');
        hintText.classList.remove('show');
        comedyMessage.classList.remove('show');
        
        updatePointsDisplay();
        renderQuestion(0);
        updateDashboard();
        updateProgress();
    } catch (error) {
        console.error('Error:', error);
        alert('Error loading questions: ' + error.message);
    } finally {
        startQuizBtn.disabled = false;
        startQuizBtn.innerHTML = '<i class="fas fa-play"></i> Start Quiz';
    }
}

// ---------- RENDER QUESTION ----------
function renderQuestion(index) {
    if (!quizStarted || quizCompleted || !questions.length) return;
    const q = questions[index];
    questionText.textContent = q.question;
    questionCounter.textContent = `Q${index+1} / ${questions.length}`;

    answerDescription.classList.remove('show');
    comedyMessage.classList.remove('show');

    if (hintUsed[index]) {
        hintContent.textContent = q.hint || 'No hint available.';
        hintText.classList.add('show');
        hintBtn.disabled = true;
    } else {
        hintText.classList.remove('show');
        hintBtn.disabled = false;
        hintBtn.innerHTML = `<i class="fas fa-lightbulb"></i> Hint (${HINT_COST} pts)`;
    }

    const letters = ['A', 'B', 'C', 'D'];
    const selectedIdx = selectedAnswers[index];
    const isLocked = lockedAnswers[index];
    let html = '';
    for (let i = 0; i < q.options.length; i++) {
        let cls = 'option';
        if (selectedIdx === i) cls += ' selected';
        if (isLocked) cls += ' locked disabled';
        if (quizCompleted) cls += ' disabled';
        const lockIcon = isLocked && selectedIdx === i ?
            '<i class="fas fa-lock" style="margin-left:auto; color:#267b8b;"></i>' : '';
        html += `
            <div class="${cls}" data-opt-index="${i}" data-q-index="${index}">
                <span class="opt-letter">${letters[i]}</span>
                <span>${q.options[i]}</span>
                ${lockIcon}
            </div>
        `;
    }
    optionsContainer.innerHTML = html;

    if (!quizCompleted && quizStarted) {
        document.querySelectorAll('.option:not(.locked)').forEach(opt => {
            opt.addEventListener('click', onOptionClick);
        });
    }

    prevBtn.disabled = (index === 0);
    const isLast = (index === questions.length - 1);
    nextBtn.textContent = isLast ? 'Finish' : 'Next';
    nextBtn.disabled = false;

    updateDashboard();
    updateProgress();
    startTimer();
}

function onOptionClick(e) {
    if (quizCompleted || !quizStarted) return;
    const div = e.currentTarget;
    const qIdx = parseInt(div.dataset.qIndex);
    const optIdx = parseInt(div.dataset.optIndex);
    if (qIdx !== currentIndex) return;
    if (lockedAnswers[currentIndex]) return;

    const elapsed = (Date.now() - questionStartTime) / 1000;
    timePerQuestion[currentIndex] = Math.min(elapsed, TIME_LIMIT);

    selectedAnswers[currentIndex] = optIdx;
    lockedAnswers[currentIndex] = true;

    const q = questions[currentIndex];
    if (optIdx === q.correct) {
        points += POINTS_PER_CORRECT;
        updatePointsDisplay();
        answerDescription.classList.remove('show');
    } else {
        descriptionText.textContent = q.description || 'No description available.';
        answerDescription.classList.add('show');
    }

    renderQuestion(currentIndex);
    updateDashboard();
    updateProgress();
}

// ---------- HINT BUTTON ----------
hintBtn.addEventListener('click', function() {
    if (quizCompleted || !quizStarted) return;
    if (hintUsed[currentIndex]) return;

    if (points >= HINT_COST) {
        points -= HINT_COST;
        updatePointsDisplay();
        hintUsed[currentIndex] = true;
        const q = questions[currentIndex];
        hintContent.textContent = q.hint || 'No hint available.';
        hintText.classList.add('show');
        hintBtn.disabled = true;
        comedyMessage.classList.remove('show');
    } else {
        const randomMsg = comedyMessages[Math.floor(Math.random() * comedyMessages.length)];
        comedyText.textContent = randomMsg;
        comedyMessage.classList.add('show');
        setTimeout(() => {
            comedyMessage.classList.remove('show');
        }, 7000);
    }
});

function goToQuestion(index) {
    if (quizCompleted || !quizStarted) return;
    if (index < 0 || index >= questions.length) return;
    if (selectedAnswers[currentIndex] !== null && !lockedAnswers[currentIndex]) {
        const elapsed = (Date.now() - questionStartTime) / 1000;
        timePerQuestion[currentIndex] = Math.min(elapsed, TIME_LIMIT);
    }
    currentIndex = index;
    renderQuestion(currentIndex);
}

// ---------- UPDATE FUNCTIONS ----------
function updatePointsDisplay() {
    pointsDisplay.textContent = points;
}

function updateProgress() {
    const total = questions.length || 1;
    let answered = 0;
    for (let i = 0; i < selectedAnswers.length; i++) {
        if (selectedAnswers[i] !== null) answered++;
    }
    const pct = Math.round((answered / total) * 100);
    progressFill.style.width = pct + '%';
    progressLabel.textContent = pct + '% complete';
    const unanswered = total - answered;
    progressUnanswered.textContent = `Unanswered: ${unanswered}`;
    unansweredCount.textContent = unanswered;
}

function updateDashboard() {
    totalQ.textContent = questions.length || 0;
    let correct = 0, wrong = 0, locked = 0, unanswered = 0;
    for (let i = 0; i < selectedAnswers.length; i++) {
        if (lockedAnswers[i]) locked++;
        if (selectedAnswers[i] === null) {
            unanswered++;
            continue;
        }
        if (selectedAnswers[i] === questions[i]?.correct) correct++;
        else wrong++;
    }
    correctCount.textContent = correct;
    wrongCount.textContent = wrong;
    lockedCount.textContent = locked;
    unansweredCount.textContent = unanswered;
    const total = correct + wrong;
    accuracy.textContent = total ? Math.round((correct / total) * 100) + '%' : '0%';
    const avg = timePerQuestion.length ? timePerQuestion.reduce((a, b) => a + b, 0) / timePerQuestion.length : 0;
    avgTime.textContent = Math.round(avg) + 's';
    updateProgress();
    updatePointsDisplay();
}

// ---------- FINISH QUIZ ----------
async function finishQuiz() {
    if (quizCompleted) return;
    stopTimer();
    quizCompleted = true;
    quizStarted = false;
    quizArea.classList.add('hidden');
    resultPanel.classList.remove('hidden');

    let correct = 0;
    const details = [];
    for (let i = 0; i < questions.length; i++) {
        const userAns = selectedAnswers[i];
        const correctAns = questions[i].correct;
        const isCorrect = (userAns === correctAns);
        if (isCorrect) correct++;
        details.push({
            index: i,
            question: questions[i].question,
            userAns: userAns,
            correctAns: correctAns,
            isCorrect: isCorrect,
            options: questions[i].options,
            time: timePerQuestion[i] || 0,
            description: questions[i].description || '',
            hintUsed: hintUsed[i] || false,
            question_id: questions[i].id || i + 1
        });
    }
    const pct = Math.round((correct / questions.length) * 100);
    if (pct >= 70) {
        triggerCelebration();
    }

    const avgTimeVal = timePerQuestion.reduce((a, b) => a + b, 0) / questions.length;

    // Save to database
    const sessionData = {
        difficulty: difficultySelect.value,
        total_questions: questions.length,
        correct_answers: correct,
        wrong_answers: questions.length - correct,
        points_earned: points,
        average_time: avgTimeVal,
        answers: details.map(d => ({
            question_id: d.question_id,
            selected_option: d.userAns !== null ? String.fromCharCode(65 + d.userAns) : null,
            is_correct: d.isCorrect,
            time_taken: d.time,
            hint_used: d.hintUsed
        }))
    };

    try {
        await saveSession(sessionData);
        console.log('✅ Session saved, reloading history...');
        // Reload history after saving
        await loadHistory();
    } catch (e) {
        console.error('Error saving session:', e);
    }

    finalScore.textContent = `${correct}/${questions.length}`;
    resultTime.textContent = Math.round(avgTimeVal) + 's avg';
    resultPoints.textContent = points;

    let badge = '';
    if (pct >= 90) badge = '🌟 Excellent';
    else if (pct >= 70) badge = '👍 Good job';
    else if (pct >= 50) badge = '📚 Keep practicing';
    else badge = '💪 Review more';
    resultBadge.textContent = badge;

    let detailHtml = '';
    const letters = ['A', 'B', 'C', 'D'];
    for (let d of details) {
        const icon = d.isCorrect ? '<i class="fas fa-check-circle" style="color:#1a7a4a;"></i>' :
            '<i class="fas fa-times-circle" style="color:#b02a3a;"></i>';
        const userLetter = (d.userAns !== null) ? letters[d.userAns] : '—';
        const correctLetter = letters[d.correctAns];
        const statusBadge = d.isCorrect ?
            `<span class="correct-badge"><i class="fas fa-check"></i> correct</span>` :
            `<span class="wrong-badge"><i class="fas fa-times"></i> wrong (${correctLetter})</span>`;
        const hintInfo = d.hintUsed ? `<span style="font-size:0.7rem; color:#f5c542; margin-left:4px;">💡 hint used</span>` : '';
        const desc = d.description ? `<span style="font-size:0.7rem; color:#5d8494; margin-left:4px;">📝 ${d.description}</span>` : '';
        detailHtml += `
            <div class="result-item">
                <span class="qnum">#${d.index+1}</span>
                <span class="qtext">${d.question}</span>
                <span class="icon-result">${icon}</span>
                <span style="font-weight:500; font-size:0.8rem;">your: ${userLetter}</span>
                ${statusBadge}
                <span style="font-size:0.7rem; color:#5d8494;">${Math.round(d.time)}s</span>
                ${hintInfo}
                ${desc}
            </div>
        `;
    }
    resultDetailGrid.innerHTML = detailHtml;
    updateDashboard();
}

// ---------- RESET ----------
function resetToSetup() {
    stopTimer();
    quizStarted = false;
    quizCompleted = false;
    quizArea.classList.add('hidden');
    resultPanel.classList.add('hidden');
    setupPanel.classList.remove('hidden');
    questions = [];
    selectedAnswers = [];
    lockedAnswers = [];
    hintUsed = [];
    timePerQuestion = [];
    points = 0;
    currentIndex = 0;
    answerDescription.classList.remove('show');
    hintText.classList.remove('show');
    comedyMessage.classList.remove('show');
    updatePointsDisplay();
    updateDashboard();
    updateProgress();
    celebrationContainer.innerHTML = '';
}

// ---------- EVENT LISTENERS ----------
startQuizBtn.addEventListener('click', buildQuiz);

nextBtn.addEventListener('click', () => {
    if (quizCompleted || !quizStarted) return;
    if (selectedAnswers[currentIndex] !== null && !lockedAnswers[currentIndex]) {
        const elapsed = (Date.now() - questionStartTime) / 1000;
        timePerQuestion[currentIndex] = Math.min(elapsed, TIME_LIMIT);
    }
    if (currentIndex === questions.length - 1) {
        finishQuiz();
    } else {
        goToQuestion(currentIndex + 1);
    }
});

prevBtn.addEventListener('click', () => {
    if (quizCompleted || !quizStarted) return;
    if (currentIndex > 0) goToQuestion(currentIndex - 1);
});

restartBtn.addEventListener('click', resetToSetup);

// ---------- LOAD USER STATS & HISTORY ----------
async function loadUserStats() {
    try {
        const response = await fetch(`${SCRIPT_NAME}?api=1&action=get_user_stats`);
        const data = await response.json();
        console.log('User stats:', data);
    } catch(e) {
        console.error('Error loading user stats:', e);
    }
}

// Load history on page load
console.log('🔄 Loading history...');
loadHistory();
loadUserStats();

console.log('✅ ProQuiz loaded successfully!');
console.log('📁 File: quiz.php');
</script>
<?php endif; ?>

</body>
</html>