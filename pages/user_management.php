
<?php

require_once "../includes/auth_check.php";
require_once "../config/database.php";

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Only Admin can manage users
if (($_SESSION["role"] ?? "") !== "admin") {
    http_response_code(403);
    die("Access denied. Only administrators can manage users.");
}

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

$message = "";
$error = "";

// Handle form submissions
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    if (
        !isset($_POST["csrf_token"]) ||
        !hash_equals($_SESSION["csrf_token"], $_POST["csrf_token"])
    ) {
        $error = "Invalid request. Please refresh the page and try again.";
    } else {

        $action = $_POST["action"] ?? "";

        // CREATE USER
        if ($action === "create_user") {

            $full_name = trim($_POST["full_name"] ?? "");
            $username = trim($_POST["username"] ?? "");
            $password = $_POST["password"] ?? "";
            $role = $_POST["role"] ?? "";

            if (
                $full_name === "" ||
                $username === "" ||
                $password === ""
            ) {
                $error = "Please complete all required fields.";

            } elseif (!preg_match('/^[a-zA-Z0-9_.-]{3,50}$/', $username)) {
                $error = "Username must be 3–50 characters and may contain letters, numbers, dots, underscores, and hyphens.";

            } elseif (strlen($password) < 8) {
                $error = "Password must contain at least 8 characters.";

            } elseif (!in_array($role, ["admin", "finance_officer"], true)) {
                $error = "Please select a valid user role.";

            } else {

                try {

                    $password_hash = password_hash(
                        $password,
                        PASSWORD_DEFAULT
                    );

                    $stmt = $pdo->prepare("
                        INSERT INTO users
                        (full_name, username, password_hash, role)
                        VALUES (?, ?, ?, ?)
                    ");

                    $stmt->execute([
                        $full_name,
                        $username,
                        $password_hash,
                        $role
                    ]);

                    header("Location: user_management.php?created=1");
                    exit;

                } catch (PDOException $ex) {

                    if ($ex->getCode() === "23000") {
                        $error = "That username is already taken.";
                    } else {
                        error_log($ex->getMessage());
                        $error = "Unable to create the user. Please try again.";
                    }
                }
            }
        }

        // ACTIVATE OR DEACTIVATE USER
        elseif ($action === "toggle_status") {

            $user_id = filter_var(
                $_POST["user_id"] ?? null,
                FILTER_VALIDATE_INT
            );

            if (!$user_id || $user_id <= 0) {
                $error = "Invalid user selected.";

            } elseif ($user_id == $_SESSION["user_id"]) {
                $error = "You cannot deactivate your own account.";

            } else {

                $stmt = $pdo->prepare("
                    SELECT id, role, is_active
                    FROM users
                    WHERE id = ?
                ");

                $stmt->execute([$user_id]);
                $target_user = $stmt->fetch();

                if (!$target_user) {
                    $error = "User not found.";

                } else {

                    $new_status = $target_user["is_active"] ? 0 : 1;

                    // Prevent deactivating the last active Admin
                    if (
                        $target_user["role"] === "admin" &&
                        $target_user["is_active"] &&
                        !$new_status
                    ) {

                        $stmt = $pdo->query("
                            SELECT COUNT(*)
                            FROM users
                            WHERE role = 'admin'
                            AND is_active = 1
                        ");

                        $active_admins = (int)$stmt->fetchColumn();

                        if ($active_admins <= 1) {
                            $error = "You cannot deactivate the last active Admin.";
                        }
                    }

                    if ($error === "") {

                        $stmt = $pdo->prepare("
                            UPDATE users
                            SET is_active = ?
                            WHERE id = ?
                        ");

                        $stmt->execute([
                            $new_status,
                            $user_id
                        ]);

                        header("Location: user_management.php?updated=1");
                        exit;
                    }
                }
            }
        }
    }
}

if (isset($_GET["created"])) {
    $message = "User account created successfully.";
}

if (isset($_GET["updated"])) {
    $message = "User status updated successfully.";
}

// Load users
$stmt = $pdo->query("
    SELECT
        id,
        full_name,
        username,
        role,
        is_active,
        created_at
    FROM users
    ORDER BY created_at DESC
");

$users = $stmt->fetchAll();

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>User Management | LedgerFlow AIS</title>


        <link rel="stylesheet" href="../assets/css/app.css">
</head>

<body>

<?php $activePage = "user_management"; require __DIR__ . "/../includes/sidebar.php"; ?>

<main class="main-content">
<div class="page-inner">

    <div class="page-header">
            <h1>User Management</h1>
            <p>Manage LedgerFlow AIS user accounts and access permissions.</p>
        </div>

    <div class="panel">

        <h2>Create New User</h2>

        <?php if ($message): ?>
            <div class="alert success"><?= e($message) ?></div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert error"><?= e($error) ?></div>
        <?php endif; ?>

        <form method="POST" class="form-grid">

            <input type="hidden" name="csrf_token"
                value="<?= e($_SESSION['csrf_token']) ?>">

            <input type="hidden" name="action" value="create_user">

            <div class="field">
                <label>Full Name</label>
                <input
                    type="text"
                    name="full_name"
                    maxlength="150"
                    required
                >
            </div>

            <div class="field">
                <label>Username</label>
                <input
                    type="text"
                    name="username"
                    minlength="3"
                    maxlength="50"
                    required
                >
            </div>

            <div class="field">
                <label>Password</label>
                <input
                    type="password"
                    name="password"
                    minlength="8"
                    required
                >
            </div>

            <div class="field">
                <label>User Role</label>
                <select name="role" required>
                    <option value="">-- Select Role --</option>
                    <option value="admin">Admin</option>
                    <option value="finance_officer">Finance Officer</option>
                </select>
            </div>

            <div class="field-full">
                <button type="submit" class="btn">
                    Create User Account
                </button>
            </div>

        </form>

    </div>

    <div class="panel">

        <h2>Registered Users</h2>

        <table>

            <thead>
                <tr>
                    <th>Full Name</th>
                    <th>Username</th>
                    <th>Role</th>
                    <th>Status</th>
                    <th>Date Created</th>
                    <th>Action</th>
                </tr>
            </thead>

            <tbody>

                <?php foreach ($users as $user): ?>

                    <tr>

                        <td><?= e($user["full_name"]) ?></td>

                        <td><?= e($user["username"]) ?></td>

                        <td>
                            <?= e(
                                $user["role"] === "admin"
                                    ? "Admin"
                                    : "Finance Officer"
                            ) ?>
                        </td>

                        <td>
                            <?php if ($user["is_active"]): ?>
                                <span class="badge badge-ok">
                                    Active
                                </span>
                            <?php else: ?>
                                <span class="badge badge-off">
                                    Inactive
                                </span>
                            <?php endif; ?>
                        </td>

                        <td><?= e($user["created_at"]) ?></td>

                        <td>

                            <?php if ($user["id"] == $_SESSION["user_id"]): ?>

                                <span>Current User</span>

                            <?php else: ?>

                                <form method="POST">

                                    <input type="hidden" name="csrf_token"
                                        value="<?= e($_SESSION['csrf_token']) ?>">

                                    <input type="hidden" name="action"
                                        value="toggle_status">

                                    <input type="hidden" name="user_id"
                                        value="<?= e($user["id"]) ?>">

                                    <?php if ($user["is_active"]): ?>

                                        <button
                                            type="submit"
                                            class="btn btn-danger btn-sm"
                                            onclick="return confirm('Deactivate this user account?')"
                                        >
                                            Deactivate
                                        </button>

                                    <?php else: ?>

                                        <button
                                            type="submit"
                                            class="btn btn-success btn-sm"
                                        >
                                            Activate
                                        </button>

                                    <?php endif; ?>

                                </form>

                            <?php endif; ?>

                        </td>

                    </tr>

                <?php endforeach; ?>

                <?php if (empty($users)): ?>
                    <tr>
                        <td colspan="6">No users found.</td>
                    </tr>
                <?php endif; ?>

            </tbody>

        </table>

    </div>

</div>
</main>

</body>
</html>
