<?php
// includes/header.php
// ============================================================
// Header bar with BCP logo + "BCP Enrollment" title
// Auto-detects app base URL so paths work in any folder
// ============================================================

if (!isset($pageTitle)) {
    $pageTitle = 'BCP Enrollment';
}

// Auto-detect base URL (safe to run multiple times)
if (!isset($baseUrl)) {
    $scriptPath = $_SERVER['SCRIPT_NAME'] ?? '';
    $basePath   = rtrim(dirname($scriptPath), '/\\');
    // Strip sub-folders so we always land on the app root
    $basePath   = preg_replace('#/(pages|api|ajax|auth)$#', '', $basePath);
    $baseUrl    = $basePath . '/';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title><?php echo htmlspecialchars($pageTitle); ?></title>

    <!-- BCP logo as favicon + apple touch icon -->
    <link rel="icon" type="image/png" href="<?php echo $baseUrl; ?>assets/bcp-logo.png">
    <link rel="apple-touch-icon" href="<?php echo $baseUrl; ?>assets/bcp-logo.png">

    <!-- Stylesheets -->
    <link rel="stylesheet" href="<?php echo $baseUrl; ?>css/styles.css">

    <!-- Fonts + Icons -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
</head>
<body>

<!-- ============================================================
     TOP BAR — BCP logo + title + realtime clock
     ============================================================ -->
<header class="top-bar">
    <div class="realtime" id="realtimeClock" aria-live="polite">--:--</div>
    </div>

</header>