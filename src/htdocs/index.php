<?php
// PowerDNS Extension - Entry Point

if (!defined('PRODUCT_ROOT')) {
    // Plesk environment not loaded — display error
    header('HTTP/1.1 403 Forbidden');
    echo 'This page can only be accessed from within Plesk.';
    exit(1);
}
