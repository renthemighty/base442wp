<?php
/**
 * B442WP — Application Entry Point
 *
 * All HTTP requests are rewritten here by the web server (Apache .htaccess or
 * Nginx try_files directive). This file:
 *
 *   1. Defines application-wide constants (APP_ROOT, APP_VERSION).
 *   2. Requires the core includes in dependency order.
 *   3. Calls boot() to start the session and output buffering.
 *   4. Hands off to the router.
 *
 * Do not add application logic here.
 */

declare(strict_types=1);

// ─── Global constants ──────────────────────────────────────────────────────────

define('APP_ROOT',    __DIR__);
define('APP_VERSION', '1.0.0');

// ─── Core includes ─────────────────────────────────────────────────────────────

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/middleware.php';

// ─── Bootstrap ─────────────────────────────────────────────────────────────────

boot();

// ─── Dispatch ──────────────────────────────────────────────────────────────────

require_once __DIR__ . '/router.php';
