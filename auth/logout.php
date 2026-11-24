
<?php
session_start();

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    exit("Method not allowed.");
}

$token = $_POST["logout_token"] ?? "";

if (
    !isset($_SESSION["logout_token"]) ||
    !is_string($token) ||
    !hash_equals($_SESSION["logout_token"], $token)
) {
    http_response_code(403);
    exit("Invalid logout request.");
}

$_SESSION = [];

if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();

    setcookie(
        session_name(),
        "",
        time() - 42000,
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}

session_destroy();

header("Location: login.php");
exit;
