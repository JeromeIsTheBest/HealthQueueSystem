<?php
/** One-shot success feedback; redirect successful form POSTs before refresh can repeat them. */
function physicianSaveFeedback(string &$flash, array $errors, string $destination): ?string
{
    $key = basename((string)parse_url($destination, PHP_URL_PATH));
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        if ($flash !== '' && !$errors) {
            $_SESSION['physician_save_feedback'][$key] = $flash;
            return $destination;
        }
    } elseif (isset($_SESSION['physician_save_feedback'][$key])) {
        $flash = (string)$_SESSION['physician_save_feedback'][$key];
        unset($_SESSION['physician_save_feedback'][$key]);
    }
    return null;
}
