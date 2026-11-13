<?php

if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

require_once __DIR__.'/includes/class-selfcap-store.php';

Selfcap_Store::uninstall();

delete_option('selfcap_options');
delete_option('selfcap_signing_key');
delete_option('selfcap_db_version');
delete_metadata('user', 0, 'selfcap_per_page', '', true);
