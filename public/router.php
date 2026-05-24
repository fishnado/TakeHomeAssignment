<?php

/**
 * PHP built-in server router.
 * Handles clean URL routing before falling back to normal file serving.
 *
 * Route: /share/{slug}/{token}
 *   slug  — human-readable document identifier (kebab + base-36 suffix)
 *   token — 32-char hex share token (the actual access credential)
 *
 * The slug is cosmetic: it makes the URL readable and identifiable.
 * The token is what grants access — view.php ignores the slug entirely,
 * so guessing a slug never bypasses the token requirement.
 *
 * Old-style /view.php?token= links continue to work unchanged.
 */

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if (preg_match('#^/share/([a-z0-9][a-z0-9-]*)/([a-f0-9]{32})$#', $uri, $m)) {
    $_GET['token'] = $m[2];
    require __DIR__ . '/view.php';
    return true;
}

// Fall through — let the built-in server serve the file normally.
return false;
