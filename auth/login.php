
<?php
session_set_cookie_params([
    "httponly" => true,
    "secure" => !empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off",
    "samesite" => "Lax"
]);

session_start();

require_once __DIR__ . "/../config/database.php";

// If already logged in, go to the dashboard.
if (isset($_SESSION["user_id"])) {
    header("Location: ../pages/dashboard.php");
    exit;
}

if (empty($_SESSION["login_token"])) {
    $_SESSION["login_token"] = bin2hex(random_bytes(32));
}

$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $token = $_POST["csrf_token"] ?? "";

    if (
        !is_string($token) ||
        !hash_equals($_SESSION["login_token"], $token)
    ) {
        $error = "Invalid request. Please refresh the page.";
    } else {
        $username = trim($_POST["username"] ?? "");
        $password = $_POST["password"] ?? "";

        $stmt = $pdo->prepare(
            "SELECT id, full_name, username, password_hash, role, is_active
             FROM users
             WHERE username = ?
             LIMIT 1"
        );

        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if (
            $user &&
            (int)$user["is_active"] === 1 &&
            password_verify($password, $user["password_hash"]) &&
            in_array($user["role"], ["admin", "finance_officer"], true)
        ) {
            session_regenerate_id(true);

            $_SESSION["user_id"] = (int)$user["id"];
            $_SESSION["full_name"] = $user["full_name"];
            $_SESSION["username"] = $user["username"];
            $_SESSION["role"] = $user["role"];

            unset($_SESSION["login_token"]);

            header("Location: ../pages/dashboard.php");
            exit;
        } else {
            $error = "Incorrect username or password, or account is inactive.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login | LedgerFlow AIS</title>

    <style>
        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #eef3f9;
            font-family: Arial, sans-serif;
        }

        .login-box {
            background: white;
            width: 90%;
            max-width: 400px;
            padding: 35px;
            border-radius: 12px;
            box-shadow: 0 5px 25px #0001;
        }

        h1 {
            color: #174a7e;
            margin-bottom: 5px;
        }

        .subtitle { color: #64748b; }

        label {
            display: block;
            margin-top: 18px;
        }

        input {
            width: 100%;
            padding: 12px;
            margin-top: 7px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
        }

        button {
            width: 100%;
            padding: 13px;
            margin-top: 24px;
            border: none;
            border-radius: 6px;
            color: white;
            background: #174a7e;
            font-weight: bold;
            cursor: pointer;
        }

        button:hover { background: #10375e; }

        .error {
            color: #b91c1c;
            background: #fee2e2;
            padding: 10px;
            border-radius: 6px;
        }
    </style>
</head>

<body>
<div class="login-box">

    <h1>LedgerFlow AIS</h1>
    <p class="subtitle">Accounting Information System</p>

    <h3>Sign in to your account</h3>

    <?php if ($error !== ""): ?>
        <p class="error">
            <?= htmlspecialchars($error) ?>
        </p>
    <?php endif; ?>

    <form method="POST">
        <input
            type="hidden"
            name="csrf_token"
            value="<?= htmlspecialchars($_SESSION["login_token"]) ?>"
        >

        <label for="username">Username</label>
        <input
            type="text"
            id="username"
            name="username"
            autocomplete="username"
            required
        >

        <label for="password">Password</label>
        <input
            type="password"
            id="password"
            name="password"
            autocomplete="current-password"
            required
        >

        <button type="submit">Log In</button>
    </form>

</div>
</body>
</html>
