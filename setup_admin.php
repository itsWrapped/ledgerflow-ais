
<?php
session_start();

require_once __DIR__ . "/config/database.php";

$message = "";
$success = false;

if (empty($_SESSION["setup_token"])) {
    $_SESSION["setup_token"] = bin2hex(random_bytes(32));
}

// Only allow initial setup if no Admin exists.
$check = $pdo->prepare(
    "SELECT COUNT(*) FROM users WHERE role = 'admin'"
);
$check->execute();
$adminExists = (int) $check->fetchColumn() > 0;

if ($_SERVER["REQUEST_METHOD"] === "POST" && !$adminExists) {

    if (
        !isset($_POST["csrf_token"]) ||
        !hash_equals($_SESSION["setup_token"], $_POST["csrf_token"])
    ) {
        $message = "Invalid request. Please refresh the page.";
    } else {
        $fullName = trim($_POST["full_name"] ?? "");
        $username = trim($_POST["username"] ?? "");
        $password = $_POST["password"] ?? "";
        $confirm = $_POST["confirm_password"] ?? "";

        if ($fullName === "" || $username === "") {
            $message = "Please complete all fields.";
        } elseif (!preg_match('/^[a-zA-Z0-9_]{4,50}$/', $username)) {
            $message = "Username must be 4-50 characters and use only letters, numbers, and underscores.";
        } elseif (strlen($password) < 10) {
            $message = "Password must be at least 10 characters.";
        } elseif ($password !== $confirm) {
            $message = "Passwords do not match.";
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);

            try {
                $insert = $pdo->prepare(
                    "INSERT INTO users
                    (full_name, username, password_hash, role)
                    VALUES (?, ?, ?, 'admin')"
                );

                $insert->execute([$fullName, $username, $hash]);

                $success = true;
                $message = "Admin account created successfully!";
                unset($_SESSION["setup_token"]);

            } catch (PDOException $e) {
                error_log($e->getMessage());
                $message = "Unable to create account. Check whether the username is already used.";
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Initial Admin Setup | LedgerFlow AIS</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background: #f1f5f9;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            margin: 0;
        }
        .container {
            background: white;
            padding: 30px;
            border-radius: 12px;
            width: 90%;
            max-width: 420px;
            box-shadow: 0 4px 18px #0001;
        }
        h2 { color: #174a7e; }
        label { display: block; margin-top: 15px; }
        input {
            box-sizing: border-box;
            width: 100%;
            padding: 11px;
            margin-top: 6px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
        }
        button {
            width: 100%;
            padding: 12px;
            margin-top: 22px;
            background: #174a7e;
            color: white;
            border: 0;
            border-radius: 6px;
            cursor: pointer;
        }
        .message { margin: 15px 0; color: #174a7e; }
    </style>
</head>
<body>
<div class="container">
    <h2>LedgerFlow AIS</h2>
    <h3>Initial Admin Setup</h3>

    <?php if ($adminExists): ?>
        <p>An Admin account already exists. Initial setup is disabled.</p>

    <?php elseif ($success): ?>
        <p class="message">
            <?= htmlspecialchars($message) ?>
        </p>
        <p>You can now proceed to the login page.</p>

    <?php else: ?>
        <?php if ($message !== ""): ?>
            <p class="message">
                <?= htmlspecialchars($message) ?>
            </p>
        <?php endif; ?>

        <form method="POST">
            <input
                type="hidden"
                name="csrf_token"
                value="<?= htmlspecialchars($_SESSION["setup_token"]) ?>"
            >

            <label>Full Name</label>
            <input type="text" name="full_name" required>

            <label>Username</label>
            <input type="text" name="username"
                   minlength="4" maxlength="50" required>

            <label>Password</label>
            <input type="password" name="password"
                   minlength="10" required>

            <label>Confirm Password</label>
            <input type="password" name="confirm_password"
                   minlength="10" required>

            <button type="submit">Create Admin Account</button>
        </form>
    <?php endif; ?>
</div>
</body>
</html>
