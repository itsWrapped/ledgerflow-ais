<?php
// Shared sidebar for every LedgerFlow AIS page.
// Usage (before including): $activePage = "dashboard";

$activePage = $activePage ?? "";

// Tokens for the logout form.
if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}
if (empty($_SESSION["logout_token"])) {
    $_SESSION["logout_token"] = bin2hex(random_bytes(32));
}

$sbEsc = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, "UTF-8");

$mainNav = [
    "dashboard"        => ["dashboard.php",        "Dashboard"],
    "journal_entries"  => ["journal_entries.php",  "Journal Entries"],
    "journal_vouchers" => ["journal_vouchers.php", "Journal Vouchers"],
    "general_ledger"   => ["general_ledger.php",   "General Ledger"],
];

$isAdmin = ($_SESSION["role"] ?? "") === "admin";
?>
<aside class="sidebar">

    <div class="sidebar-brand">
        <h2>LedgerFlow AIS</h2>
        <p>Accounting Information System</p>
    </div>

    <nav class="sidebar-menu">
        <p class="menu-label">MAIN MENU</p>

        <?php foreach ($mainNav as $key => [$href, $label]): ?>
            <a href="<?= $sbEsc($href) ?>"
               class="<?= $activePage === $key ? 'active' : '' ?>"><?= $sbEsc($label) ?></a>
        <?php endforeach; ?>

        <?php if ($isAdmin): ?>
            <p class="menu-label">ADMINISTRATION</p>
            <a href="user_management.php"
               class="<?= $activePage === 'user_management' ? 'active' : '' ?>">User Management</a>
        <?php endif; ?>
    </nav>

    <div class="sidebar-footer">
        <form action="../auth/logout.php" method="POST">
            <input type="hidden" name="csrf_token"
                   value="<?= $sbEsc($_SESSION["csrf_token"]) ?>">
            <input type="hidden" name="logout_token"
                   value="<?= $sbEsc($_SESSION["logout_token"]) ?>">
            <button type="submit" class="logout-button">Logout</button>
        </form>
    </div>

</aside>
