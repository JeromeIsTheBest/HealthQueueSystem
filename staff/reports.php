<?php
require_once __DIR__.'/../includes/auth.php';
define('HQ_BASE_URL','..');
requireRole(['Staff']);
require __DIR__.'/../includes/reports-page.php';
