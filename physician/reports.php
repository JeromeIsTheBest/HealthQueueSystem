<?php
require_once __DIR__.'/../includes/auth.php';
define('HQ_BASE_URL','..');
requireRole(['Physician']);
require __DIR__.'/../includes/reports-page.php';
