<?php
// PHP Note Taking System (index.php)

// Start PHP session
session_start();

// --- Database Configuration ---
define('DB_SERVER', 'localhost'); // Your database server
define('DB_USERNAME', 'root');     // Your database username
define('DB_PASSWORD', 'root123');         // Your database password
define('DB_NAME', 'notenest_db');  // Your database name

// Image upload directory
define('UPLOAD_DIR', 'uploads/');

// Ensure the uploads directory exists and is writable
if (!is_dir(UPLOAD_DIR)) {
    mkdir(UPLOAD_DIR, 0755, true); // Create directory with read/write permissions
}

// --- Database Connection ---
$conn = null;
try {
    $conn = new PDO("mysql:host=localhost;dbname=NotetakingSystem", "root", "root123");
    // Set the PDO error mode to exception
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("ERROR: Could not connect. " . $e->getMessage());
}

// --- Global Variables ---
$current_page = isset($_GET['page']) ? $_GET['page'] : 'login';
$user_id = $_SESSION['user_id'] ?? null;
$error_message = '';
$success_message = '';

// --- Helper Function for Redirecting ---
function redirect($page) {
    header("Location: index.php?page=" . $page);
    exit();
}

// --- Authentication Functions ---
function registerUser($email, $password, $conn) {
    global $error_message, $success_message;

    if (empty($email) || empty($password)) {
        $error_message = "Email and password cannot be empty.";
        return false;
    }

    $hashed_password = password_hash($password, PASSWORD_BCRYPT); // Hash the password

    try {
        $stmt = $conn->prepare("INSERT INTO users (email, password) VALUES (:email, :password)");
        $stmt->bindParam(":email", $email);
        $stmt->bindParam(":password", $hashed_password);
        $stmt->execute();
        $success_message = "Registration successful! Please log in.";
        return true;
    } catch (PDOException $e) {
        if ($e->getCode() == 23000) { // Duplicate entry error code for unique constraint
            $error_message = "This email is already registered.";
        } else {
            $error_message = "Registration failed: " . $e->getMessage();
        }
        return false;
    }
}

function loginUser($email, $password, $conn) {
    global $error_message, $success_message;

    if (empty($email) || empty($password)) {
        $error_message = "Email and password cannot be empty.";
        return false;
    }

    try {
        $stmt = $conn->prepare("SELECT id, password FROM users WHERE email = :email");
        $stmt->bindParam(":email", $email);
        $stmt->execute();
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_email'] = $email;
            $success_message = "Login successful!";
            return true;
        } else {
            $error_message = "Invalid email or password.";
            return false;
        }
    } catch (PDOException $e) {
        $error_message = "Login failed: " . $e->getMessage();
        return false;
    }
}

function logoutUser() {
    session_unset();
    session_destroy();
    redirect('login');
}

// --- Note Management Functions (CRUD) ---
function createNote($user_id, $title, $content, $image_file, $conn) {
    global $error_message, $success_message;

    if (empty($title) || empty($content)) {
        $error_message = "Title and content cannot be empty.";
        return false;
    }

    $image_path = null;
    if ($image_file && $image_file['error'] == UPLOAD_ERR_OK) {
        // Validate and move uploaded file
        $file_name = uniqid() . '_' . basename($image_file['name']);
        $target_file = UPLOAD_DIR . $file_name;
        $imageFileType = strtolower(pathinfo($target_file, PATHINFO_EXTENSION));

        // Basic validation
        $check = getimagesize($image_file["tmp_name"]);
        if ($check === false) {
            $error_message = "File is not an image.";
            return false;
        }
        if ($image_file["size"] > 5 * 1024 * 1024) { // 5MB limit
            $error_message = "Sorry, your file is too large (max 5MB).";
            return false;
        }
        if (!in_array($imageFileType, ['jpg', 'png', 'jpeg', 'gif'])) {
            $error_message = "Sorry, only JPG, JPEG, PNG & GIF files are allowed.";
            return false;
        }

        if (move_uploaded_file($image_file["tmp_name"], $target_file)) {
            $image_path = $target_file;
        } else {
            $error_message = "Sorry, there was an error uploading your file.";
            return false;
        }
    }

    try {
        $stmt = $conn->prepare("INSERT INTO notes (user_id, title, content, image_path) VALUES (:user_id, :title, :content, :image_path)");
        $stmt->bindParam(":user_id", $user_id);
        $stmt->bindParam(":title", $title);
        $stmt->bindParam(":content", $content);
        $stmt->bindParam(":image_path", $image_path);
        $stmt->execute();
        $success_message = "Note created successfully!";
        return true;
    } catch (PDOException $e) {
        $error_message = "Failed to create note: " . $e->getMessage();
        // If note creation fails, try to delete the uploaded image
        if ($image_path && file_exists($image_path)) {
            unlink($image_path);
        }
        return false;
    }
}

function getNotes($user_id, $conn) {
    try {
        $stmt = $conn->prepare("SELECT * FROM notes WHERE user_id = :user_id ORDER BY created_at DESC");
        $stmt->bindParam(":user_id", $user_id);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // Log error but don't expose sensitive info to user
        error_log("Error fetching notes: " . $e->getMessage());
        return [];
    }
}

function getNoteById($note_id, $user_id, $conn) {
    try {
        $stmt = $conn->prepare("SELECT * FROM notes WHERE id = :id AND user_id = :user_id");
        $stmt->bindParam(":id", $note_id);
        $stmt->bindParam(":user_id", $user_id);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error fetching note: " . $e->getMessage());
        return null;
    }
}

function updateNote($note_id, $user_id, $title, $content, $image_file, $remove_image, $conn) {
    global $error_message, $success_message;

    if (empty($title) || empty($content)) {
        $error_message = "Title and content cannot be empty.";
        return false;
    }

    $existing_note = getNoteById($note_id, $user_id, $conn);
    if (!$existing_note) {
        $error_message = "Note not found or you don't have permission to edit it.";
        return false;
    }

    $image_path = $existing_note['image_path']; // Start with existing path

    // Handle image removal
    if ($remove_image) {
        if ($image_path && file_exists($image_path)) {
            unlink($image_path); // Delete existing file
        }
        $image_path = null;
    }

    // Handle new image upload
    if ($image_file && $image_file['error'] == UPLOAD_ERR_OK) {
        // Delete old image if new one is uploaded
        if ($existing_note['image_path'] && file_exists($existing_note['image_path'])) {
            unlink($existing_note['image_path']);
        }

        $file_name = uniqid() . '_' . basename($image_file['name']);
        $target_file = UPLOAD_DIR . $file_name;
        $imageFileType = strtolower(pathinfo($target_file, PATHINFO_EXTENSION));

        // Basic validation (same as create)
        $check = getimagesize($image_file["tmp_name"]);
        if ($check === false) {
            $error_message = "File is not an image.";
            return false;
        }
        if ($image_file["size"] > 5 * 1024 * 1024) { // 5MB limit
            $error_message = "Sorry, your file is too large (max 5MB).";
            return false;
        }
        if (!in_array($imageFileType, ['jpg', 'png', 'jpeg', 'gif'])) {
            $error_message = "Sorry, only JPG, JPEG, PNG & GIF files are allowed.";
            return false;
        }

        if (move_uploaded_file($image_file["tmp_name"], $target_file)) {
            $image_path = $target_file;
        } else {
            $error_message = "Sorry, there was an error uploading your new file.";
            return false;
        }
    }

    try {
        $stmt = $conn->prepare("UPDATE notes SET title = :title, content = :content, image_path = :image_path WHERE id = :id AND user_id = :user_id");
        $stmt->bindParam(":title", $title);
        $stmt->bindParam(":content", $content);
        $stmt->bindParam(":image_path", $image_path);
        $stmt->bindParam(":id", $note_id);
        $stmt->bindParam(":user_id", $user_id);
        $stmt->execute();
        $success_message = "Note updated successfully!";
        return true;
    } catch (PDOException $e) {
        $error_message = "Failed to update note: " . $e->getMessage();
        return false;
    }
}

function deleteNote($note_id, $user_id, $conn) {
    global $error_message, $success_message;

    // First, get the note to check for an associated image file
    $note_to_delete = getNoteById($note_id, $user_id, $conn);

    if (!$note_to_delete) {
        $error_message = "Note not found or you don't have permission to delete it.";
        return false;
    }

    try {
        $stmt = $conn->prepare("DELETE FROM notes WHERE id = :id AND user_id = :user_id");
        $stmt->bindParam(":id", $note_id);
        $stmt->bindParam(":user_id", $user_id);
        $stmt->execute();

        if ($stmt->rowCount() > 0) {
            // Delete associated image file if it exists
            if ($note_to_delete['image_path'] && file_exists($note_to_delete['image_path'])) {
                unlink($note_to_delete['image_path']);
            }
            $success_message = "Note deleted successfully!";
            return true;
        } else {
            $error_message = "Failed to delete note or note not found.";
            return false;
        }
    } catch (PDOException $e) {
        $error_message = "Failed to delete note: " . $e->getMessage();
        return false;
    }
}


// --- Handle Form Submissions ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'register':
                if (registerUser($_POST['email'], $_POST['password'], $conn)) {
                    // Stay on login page after successful registration
                    $current_page = 'login';
                } else {
                    $current_page = 'register';
                }
                break;
            case 'login':
                if (loginUser($_POST['email'], $_POST['password'], $conn)) {
                    redirect('notes');
                } else {
                    $current_page = 'login';
                }
                break;
            case 'logout':
                logoutUser();
                break;
            case 'create_note':
                if ($user_id) {
                    $image_file = $_FILES['note_image'] ?? null;
                    if (createNote($user_id, $_POST['title'], $_POST['content'], $image_file, $conn)) {
                        redirect('notes');
                    } else {
                        $current_page = 'createNote'; // Stay on form if error
                    }
                }
                break;
            case 'update_note':
                if ($user_id && isset($_POST['note_id'])) {
                    $image_file = $_FILES['note_image'] ?? null;
                    $remove_image = isset($_POST['remove_image']) ? true : false;
                    if (updateNote($_POST['note_id'], $user_id, $_POST['title'], $_POST['content'], $image_file, $remove_image, $conn)) {
                        redirect('viewNote&id=' . $_POST['note_id']);
                    } else {
                        $current_page = 'editNote'; // Stay on form if error
                        $_GET['id'] = $_POST['note_id']; // Retain note ID for display
                    }
                }
                break;
            case 'delete_note':
                if ($user_id && isset($_POST['note_id'])) {
                    if (deleteNote($_POST['note_id'], $user_id, $conn)) {
                        redirect('notes');
                    } else {
                        $current_page = 'notes'; // Stay on notes list if error
                    }
                }
                break;
        }
    }
}

// --- Dynamic Page Routing (based on $current_page and user_id) ---
// If user is not logged in, force to login/register pages
if (!$user_id && !in_array($current_page, ['login', 'register'])) {
    $current_page = 'login';
}

// --- HTML Structure ---
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NoteNest - PHP Note Taking System</title>
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Inter', sans-serif;
        }
        .prose img {
            max-width: 100%;
            height: auto;
            border-radius: 0.5rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            border: 1px solid #e2e8f0; /* light gray border */
        }
        .modal-overlay {
            background-color: rgba(0, 0, 0, 0.5);
        }
        .animate-spin {
            animation: spin 1s linear infinite;
        }
        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
        .line-clamp-3 {
            display: -webkit-box;
            -webkit-line-clamp: 3;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        .break-words {
            word-break: break-word;
        }
        /* Custom file input styling */
        input[type="file"]::-webkit-file-upload-button {
            cursor: pointer;
        }
        input[type="file"]::file-selector-button {
            cursor: pointer;
        }
    </style>
</head>
<body class="min-h-screen bg-gray-100 dark:bg-gray-900 text-gray-900 dark:text-gray-100 flex flex-col items-center p-4 sm:p-8">
    <header class="w-full max-w-4xl text-center mb-8">
        <h1 class="text-4xl sm:text-5xl font-extrabold text-blue-600 dark:text-blue-400 mb-2 flex items-center justify-center">
            <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="inline-block mr-2 text-blue-500">
                <path d="M13 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9Z"/><path d="M17 2v6h6"/><path d="M13 12H9"/><path d="M13 16H9"/><path d="M13 20H9"/>
            </svg>
            NoteNest
        </h1>
        <p class="text-lg text-gray-700 dark:text-gray-300">Your personal space for thoughts and ideas.</p>
        <?php if ($user_id): ?>
            <div class="mt-4 text-sm text-gray-600 dark:text-gray-400 p-2 bg-gray-200 dark:bg-gray-800 rounded-md shadow-inner">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="inline-block mr-1">
                    <path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>
                </svg>
                Current User ID: <span class="font-mono break-all"><?php echo htmlspecialchars($user_id); ?></span>
            </div>
        <?php endif; ?>
    </header>

    <main class="w-full max-w-4xl bg-white dark:bg-gray-800 rounded-xl shadow-2xl p-6 sm:p-8">
        <?php if ($error_message): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4" role="alert">
                <strong class="font-bold">Error!</strong>
                <span class="block sm:inline"><?php echo htmlspecialchars($error_message); ?></span>
            </div>
        <?php endif; ?>
        <?php if ($success_message): ?>
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative mb-4" role="alert">
                <strong class="font-bold">Success!</strong>
                <span class="block sm:inline"><?php echo htmlspecialchars($success_message); ?></span>
            </div>
        <?php endif; ?>

        <?php
        // --- Page Content Rendering ---
        if ($current_page === 'login') {
            ?>
            <div class="flex flex-col items-center justify-center p-4">
                <h2 class="text-3xl font-bold mb-6 text-blue-600 dark:text-blue-400 flex items-center">
                    <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="inline-block mr-2">
                        <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" x2="3" y1="12" y2="12"/>
                    </svg>
                    Login
                </h2>
                <form action="index.php" method="POST" class="w-full max-w-sm space-y-4">
                    <input type="hidden" name="action" value="login">
                    <div>
                        <label for="email" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Email</label>
                        <input
                            type="email"
                            id="email"
                            name="email"
                            required
                            class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 bg-gray-50 dark:bg-gray-700 text-gray-900 dark:text-gray-100"
                            value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
                        />
                    </div>
                    <div>
                        <label for="password" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Password</label>
                        <input
                            type="password"
                            id="password"
                            name="password"
                            required
                            class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 bg-gray-50 dark:bg-gray-700 text-gray-900 dark:text-gray-100"
                        />
                    </div>
                    <button
                        type="submit"
                        class="w-full bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded-lg shadow-md transition duration-300 ease-in-out transform hover:scale-105 flex items-center justify-center"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="inline-block mr-2">
                            <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" x2="3" y1="12" y2="12"/>
                        </svg>
                        Login
                    </button>
                </form>
                <p class="mt-6 text-gray-700 dark:text-gray-300">
                    Don't have an account?
                    <a href="index.php?page=register" class="text-blue-600 dark:text-blue-400 hover:underline font-medium ml-1">
                        Register here
                    </a>
                </p>
            </div>
            <?php
        } elseif ($current_page === 'register') {
            ?>
            <div class="flex flex-col items-center justify-center p-4">
                <h2 class="text-3xl font-bold mb-6 text-blue-600 dark:text-blue-400 flex items-center">
                    <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="inline-block mr-2">
                        <path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>
                    </svg>
                    Register
                </h2>
                <form action="index.php" method="POST" class="w-full max-w-sm space-y-4">
                    <input type="hidden" name="action" value="register">
                    <div>
                        <label for="email" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Email</label>
                        <input
                            type="email"
                            id="email"
                            name="email"
                            required
                            class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 bg-gray-50 dark:bg-gray-700 text-gray-900 dark:text-gray-100"
                            value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
                        />
                    </div>
                    <div>
                        <label for="password" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Password</label>
                        <input
                            type="password"
                            id="password"
                            name="password"
                            required
                            class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 bg-gray-50 dark:bg-gray-700 text-gray-900 dark:text-gray-100"
                        />
                    </div>
                    <button
                        type="submit"
                        class="w-full bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded-lg shadow-md transition duration-300 ease-in-out transform hover:scale-105 flex items-center justify-center"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="inline-block mr-2">
                            <path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>
                        </svg>
                        Register
                    </button>
                </form>
                <p class="mt-6 text-gray-700 dark:text-gray-300">
                    Already have an account?
                    <a href="index.php?page=login" class="text-blue-600 dark:text-blue-400 hover:underline font-medium ml-1">
                        Login here
                    </a>
                </p>
            </div>
            <?php
        } elseif ($current_page === 'notes' && $user_id) {
            $notes = getNotes($user_id, $conn);
            ?>
            <div class="p-4">
                <div class="flex justify-between items-center mb-6">
                    <h2 class="text-3xl font-bold text-blue-600 dark:text-blue-400">Your Notes</h2>
                    <div class="flex space-x-2">
                        <a href="index.php?page=createNote"
                            class="flex items-center bg-green-500 hover:bg-green-600 text-white font-semibold py-2 px-4 rounded-lg shadow-md transition duration-300 ease-in-out transform hover:scale-105"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="inline-block mr-2">
                                <line x1="12" x2="12" y1="5" y2="19"/><line x1="5" x2="19" y1="12" y2="12"/>
                            </svg>
                            Add Note
                        </a>
                        <form action="index.php" method="POST" class="inline">
                            <input type="hidden" name="action" value="logout">
                            <button
                                type="submit"
                                class="flex items-center bg-red-500 hover:bg-red-600 text-white font-semibold py-2 px-4 rounded-lg shadow-md transition duration-300 ease-in-out transform hover:scale-105"
                            >
                                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="inline-block mr-2">
                                    <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" x2="9" y1="12" y2="12"/>
                                </svg>
                                Sign Out
                            </button>
                        </form>
                    </div>
                </div>

                <?php if (empty($notes)): ?>
                    <p class="text-center text-gray-600 dark:text-gray-400 text-lg mt-10">No notes yet. Click "Add Note" to create your first one!</p>
                <?php else: ?>
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                        <?php foreach ($notes as $note): ?>
                            <div
                                class="bg-gray-50 dark:bg-gray-700 rounded-lg shadow-lg hover:shadow-xl transition-shadow duration-300 ease-in-out p-6 flex flex-col justify-between border border-gray-200 dark:border-gray-600"
                            >
                                <div>
                                    <h3 class="text-xl font-semibold mb-2 text-blue-700 dark:text-blue-300 truncate"><?php echo htmlspecialchars($note['title']); ?></h3>
                                    <p class="text-gray-700 dark:text-gray-300 text-sm line-clamp-3 mb-4"><?php echo htmlspecialchars($note['content']); ?></p>
                                </div>
                                <div class="flex justify-end space-x-2 mt-4">
                                    <a href="index.php?page=viewNote&id=<?php echo $note['id']; ?>"
                                        class="p-2 bg-blue-500 hover:bg-blue-600 text-white rounded-full shadow-md transition duration-200 ease-in-out transform hover:scale-110"
                                        title="View Note"
                                    >
                                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/>
                                        </svg>
                                    </a>
                                    <a href="index.php?page=editNote&id=<?php echo $note['id']; ?>"
                                        class="p-2 bg-yellow-500 hover:bg-yellow-600 text-white rounded-full shadow-md transition duration-200 ease-in-out transform hover:scale-110"
                                        title="Edit Note"
                                    >
                                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4Z"/><path d="m15 5 3 3"/>
                                        </svg>
                                    </a>
                                    <button
                                        onclick="showDeleteModal(<?php echo $note['id']; ?>)"
                                        class="p-2 bg-red-500 hover:bg-red-600 text-white rounded-full shadow-md transition duration-200 ease-in-out transform hover:scale-110"
                                        title="Delete Note"
                                    >
                                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>
                                        </svg>
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <!-- Delete Confirmation Modal (PHP controlled visibility) -->
                <div id="deleteModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4 z-50 hidden">
                    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-xl p-6 w-full max-w-sm text-center">
                        <h3 class="text-xl font-bold mb-4 text-gray-900 dark:text-gray-100">Confirm Deletion</h3>
                        <p class="text-gray-700 dark:text-gray-300 mb-6">Are you sure you want to delete this note? This action cannot be undone.</p>
                        <div class="flex justify-center space-x-4">
                            <form action="index.php" method="POST" id="deleteNoteForm">
                                <input type="hidden" name="action" value="delete_note">
                                <input type="hidden" name="note_id" id="modalNoteId">
                                <button
                                    type="submit"
                                    class="px-6 py-2 bg-red-600 hover:bg-red-700 text-white font-semibold rounded-lg shadow-md transition duration-300"
                                >
                                    Delete
                                </button>
                            </form>
                            <button
                                onclick="hideDeleteModal()"
                                class="px-6 py-2 bg-gray-300 hover:bg-gray-400 text-gray-800 font-semibold rounded-lg shadow-md transition duration-300"
                            >
                                Cancel
                            </button>
                        </div>
                    </div>
                </div>

                <script>
                    function showDeleteModal(noteId) {
                        document.getElementById('modalNoteId').value = noteId;
                        document.getElementById('deleteModal').classList.remove('hidden');
                    }

                    function hideDeleteModal() {
                        document.getElementById('deleteModal').classList.add('hidden');
                    }
                </script>
            </div>
            <?php
        } elseif ($current_page === 'createNote' && $user_id) {
            ?>
            <div class="p-4">
                <h2 class="text-3xl font-bold mb-6 text-blue-600 dark:text-blue-400 flex items-center">
                    <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="inline-block mr-2">
                        <line x1="12" x2="12" y1="5" y2="19"/><line x1="5" x2="19" y1="12" y2="12"/>
                    </svg>
                    Create New Note
                </h2>
                <form action="index.php" method="POST" enctype="multipart/form-data" class="space-y-6">
                    <input type="hidden" name="action" value="create_note">
                    <div>
                        <label for="title" class="block text-lg font-medium text-gray-700 dark:text-gray-300 mb-2">Title</label>
                        <input
                            type="text"
                            id="title"
                            name="title"
                            class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 bg-gray-50 dark:bg-gray-700 text-gray-900 dark:text-gray-100"
                            required
                            placeholder="Enter note title..."
                            value="<?php echo htmlspecialchars($_POST['title'] ?? ''); ?>"
                        />
                    </div>
                    <div>
                        <label for="content" class="block text-lg font-medium text-gray-700 dark:text-gray-300 mb-2">Content</label>
                        <textarea
                            id="content"
                            name="content"
                            rows="8"
                            class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 bg-gray-50 dark:bg-gray-700 text-gray-900 dark:text-gray-100 resize-y"
                            required
                            placeholder="Write your note here..."
                        ><?php echo htmlspecialchars($_POST['content'] ?? ''); ?></textarea>
                    </div>
                    <div class="border border-dashed border-gray-400 dark:border-gray-600 rounded-lg p-4 bg-gray-50 dark:bg-gray-700">
                        <label for="note_image" class="block text-lg font-medium text-gray-700 dark:text-gray-300 mb-2 flex items-center">
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="inline-block mr-2">
                                <path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h7"/><line x1="16" x2="22" y1="5" y2="5"/><line x1="19" x2="19" y1="2" y2="8"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.5-3.5L11 22"/>
                            </svg>
                            Optional Image
                        </label>
                        <input
                            type="file"
                            id="note_image"
                            name="note_image"
                            accept="image/*"
                            class="block w-full text-sm text-gray-900 dark:text-gray-100
                                file:mr-4 file:py-2 file:px-4
                                file:rounded-full file:border-0
                                file:text-sm file:font-semibold
                                file:bg-blue-50 file:text-blue-700
                                hover:file:bg-blue-100"
                        />
                        <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">
                          Max file size: 5MB. Allowed formats: JPG, JPEG, PNG, GIF.
                        </p>
                    </div>

                    <div class="flex justify-end space-x-4 mt-6">
                        <a href="index.php?page=notes"
                            class="px-6 py-2 bg-gray-300 hover:bg-gray-400 text-gray-800 font-semibold rounded-lg shadow-md transition duration-300 transform hover:scale-105"
                        >
                            Cancel
                        </a>
                        <button
                            type="submit"
                            class="flex items-center px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white font-bold rounded-lg shadow-md transition duration-300 ease-in-out transform hover:scale-105"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="inline-block mr-2">
                                <path d="M13 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9Z"/><path d="M17 2v6h6"/><path d="M13 12H9"/><path d="M13 16H9"/><path d="M13 20H9"/>
                            </svg>
                            Create Note
                        </button>
                    </div>
                </form>
            </div>
            <?php
        } elseif ($current_page === 'viewNote' && $user_id && isset($_GET['id'])) {
            $note_id = (int)$_GET['id'];
            $note = getNoteById($note_id, $user_id, $conn);
            if (!$note) {
                $error_message = "Note not found.";
            }
            ?>
            <div class="p-4">
                <div class="flex justify-between items-start mb-6">
                    <h2 class="text-4xl font-extrabold text-blue-700 dark:text-blue-300 break-words max-w-[calc(100%-100px)]">
                        <?php echo htmlspecialchars($note['title'] ?? 'Note Not Found'); ?>
                    </h2>
                    <a href="index.php?page=notes"
                        class="p-3 bg-gray-300 hover:bg-gray-400 text-gray-800 rounded-full shadow-md transition duration-300 transform hover:scale-110"
                        title="Back to Notes"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M18 6 6 18"/><path d="m6 6 12 12"/>
                        </svg>
                    </a>
                </div>

                <?php if ($note): ?>
                    <div class="text-sm text-gray-500 dark:text-gray-400 mb-4 space-y-1">
                        <p><strong>Created:</strong> <?php echo htmlspecialchars(date('M d, Y H:i:s', strtotime($note['created_at']))); ?></p>
                        <p><strong>Last Updated:</strong> <?php echo htmlspecialchars(date('M d, Y H:i:s', strtotime($note['updated_at']))); ?></p>
                    </div>

                    <div class="prose dark:prose-invert max-w-none mb-6">
                        <p class="text-lg text-gray-800 dark:text-gray-200 whitespace-pre-wrap"><?php echo htmlspecialchars($note['content']); ?></p>
                    </div>

                    <?php if ($note['image_path'] && file_exists($note['image_path'])): ?>
                        <div class="mb-6 border border-gray-300 dark:border-gray-600 rounded-lg shadow-md overflow-hidden">
                            <img src="<?php echo htmlspecialchars($note['image_path']); ?>" alt="Note Media" class="w-full h-auto object-contain rounded-lg" />
                        </div>
                    <?php endif; ?>

                    <div class="flex justify-end space-x-4 mt-6">
                        <a href="index.php?page=editNote&id=<?php echo $note['id']; ?>"
                            class="flex items-center px-6 py-3 bg-yellow-500 hover:bg-yellow-600 text-white font-semibold rounded-lg shadow-md transition duration-300 ease-in-out transform hover:scale-105"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="inline-block mr-2">
                                <path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4Z"/><path d="m15 5 3 3"/>
                            </svg>
                            Edit
                        </a>
                        <button
                            onclick="showDeleteModal(<?php echo $note['id']; ?>)"
                            class="flex items-center px-6 py-3 bg-red-500 hover:bg-red-600 text-white font-semibold rounded-lg shadow-md transition duration-300 ease-in-out transform hover:scale-105"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="inline-block mr-2">
                                <path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>
                            </svg>
                            Delete
                        </button>
                    </div>
                <?php endif; ?>
            </div>
            <script>
                // Need to re-define showDeleteModal for this page context if it's the only place it's needed
                // Otherwise, it can be defined once in the global script section
                function showDeleteModal(noteId) {
                    document.getElementById('modalNoteId').value = noteId;
                    document.getElementById('deleteModal').classList.remove('hidden');
                }

                function hideDeleteModal() {
                    document.getElementById('deleteModal').classList.add('hidden');
                }
            </script>
            <?php
            // Re-include the delete modal HTML directly if not already globally included.
            // For this single-file setup, a global declaration in the footer script section would be better.
            // For now, repeating it here for simplicity given the previous React structure.
            ?>
            <div id="deleteModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4 z-50 hidden">
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow-xl p-6 w-full max-w-sm text-center">
                    <h3 class="text-xl font-bold mb-4 text-gray-900 dark:text-gray-100">Confirm Deletion</h3>
                    <p class="text-gray-700 dark:text-gray-300 mb-6">Are you sure you want to delete this note? This action cannot be undone.</p>
                    <div class="flex justify-center space-x-4">
                        <form action="index.php" method="POST" id="deleteNoteForm">
                            <input type="hidden" name="action" value="delete_note">
                            <input type="hidden" name="note_id" id="modalNoteId">
                            <button
                                type="submit"
                                class="px-6 py-2 bg-red-600 hover:bg-red-700 text-white font-semibold rounded-lg shadow-md transition duration-300"
                            >
                                Delete
                            </button>
                        </form>
                        <button
                            onclick="hideDeleteModal()"
                            class="px-6 py-2 bg-gray-300 hover:bg-gray-400 text-gray-800 font-semibold rounded-lg shadow-md transition duration-300"
                        >
                            Cancel
                        </button>
                    </div>
                </div>
            </div>
            <?php
        } elseif ($current_page === 'editNote' && $user_id && isset($_GET['id'])) {
            $note_id = (int)$_GET['id'];
            $note = getNoteById($note_id, $user_id, $conn);
            if (!$note) {
                $error_message = "Note not found or you don't have permission to edit it.";
                $current_page = 'notes'; // Redirect to notes list if note not found
            }
            ?>
            <div class="p-4">
                <h2 class="text-3xl font-bold mb-6 text-blue-600 dark:text-blue-400 flex items-center">
                    <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="inline-block mr-2">
                        <path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4Z"/><path d="m15 5 3 3"/>
                    </svg>
                    Edit Note
                </h2>
                <?php if ($note): ?>
                <form action="index.php" method="POST" enctype="multipart/form-data" class="space-y-6">
                    <input type="hidden" name="action" value="update_note">
                    <input type="hidden" name="note_id" value="<?php echo htmlspecialchars($note['id']); ?>">
                    <div>
                        <label for="title" class="block text-lg font-medium text-gray-700 dark:text-gray-300 mb-2">Title</label>
                        <input
                            type="text"
                            id="title"
                            name="title"
                            class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 bg-gray-50 dark:bg-gray-700 text-gray-900 dark:text-gray-100"
                            required
                            placeholder="Enter note title..."
                            value="<?php echo htmlspecialchars($note['title']); ?>"
                        />
                    </div>
                    <div>
                        <label for="content" class="block text-lg font-medium text-gray-700 dark:text-gray-300 mb-2">Content</label>
                        <textarea
                            id="content"
                            name="content"
                            rows="8"
                            class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 bg-gray-50 dark:bg-gray-700 text-gray-900 dark:text-gray-100 resize-y"
                            required
                            placeholder="Write your note here..."
                        ><?php echo htmlspecialchars($note['content']); ?></textarea>
                    </div>
                    <div class="border border-dashed border-gray-400 dark:border-gray-600 rounded-lg p-4 bg-gray-50 dark:bg-gray-700">
                        <label for="note_image" class="block text-lg font-medium text-gray-700 dark:text-gray-300 mb-2 flex items-center">
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="inline-block mr-2">
                                <path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h7"/><line x1="16" x2="22" y1="5" y2="5"/><line x1="19" x2="19" y1="2" y2="8"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.5-3.5L11 22"/>
                            </svg>
                            Optional Image
                        </label>
                        <?php if ($note['image_path']): ?>
                            <div class="mt-4 relative group">
                                <img src="<?php echo htmlspecialchars($note['image_path']); ?>" alt="Current Note Media" class="max-w-full h-auto rounded-lg shadow-md border border-gray-300 dark:border-gray-600" />
                                <button
                                    type="button"
                                    onclick="document.getElementById('remove_image_checkbox').checked = true; document.getElementById('image_preview_container').classList.add('hidden');"
                                    class="absolute top-2 right-2 p-1 bg-red-500 text-white rounded-full opacity-0 group-hover:opacity-100 transition-opacity duration-300"
                                    title="Remove Image"
                                >
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M18 6 6 18"/><path d="m6 6 12 12"/>
                                    </svg>
                                </button>
                                <p class="mt-2 text-sm text-gray-600 dark:text-gray-400" id="image_preview_container">
                                    Current image. Upload a new one to replace, or click 'X' to remove.
                                </p>
                            </div>
                            <input type="checkbox" name="remove_image" id="remove_image_checkbox" class="hidden">
                        <?php endif; ?>
                        <input
                            type="file"
                            id="note_image"
                            name="note_image"
                            accept="image/*"
                            class="block w-full text-sm text-gray-900 dark:text-gray-100 mt-4
                                file:mr-4 file:py-2 file:px-4
                                file:rounded-full file:border-0
                                file:text-sm file:font-semibold
                                file:bg-blue-50 file:text-blue-700
                                hover:file:bg-blue-100"
                        />
                        <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">
                          Max file size: 5MB. Allowed formats: JPG, JPEG, PNG, GIF.
                        </p>
                    </div>

                    <div class="flex justify-end space-x-4 mt-6">
                        <a href="index.php?page=viewNote&id=<?php echo $note['id']; ?>"
                            class="px-6 py-2 bg-gray-300 hover:bg-gray-400 text-gray-800 font-semibold rounded-lg shadow-md transition duration-300 transform hover:scale-105"
                        >
                            Cancel
                        </a>
                        <button
                            type="submit"
                            class="flex items-center px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white font-bold rounded-lg shadow-md transition duration-300 ease-in-out transform hover:scale-105"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="inline-block mr-2">
                                <path d="M13 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9Z"/><path d="M17 2v6h6"/><path d="M13 12H9"/><path d="M13 16H9"/><path d="M13 20H9"/>
                            </svg>
                            Save Note
                        </button>
                    </div>
                </form>
                <?php endif; ?>
            </div>
            <?php
        }
        ?>
    </main>
</body>
</html>
<?php
// Close database connection
$conn = null;
?>

