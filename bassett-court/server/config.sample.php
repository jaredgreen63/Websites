<?php
/**
 * Copy this file to config.php and fill it in. config.php is git-ignored and
 * never served as text — Apache executes .php rather than printing it, and the
 * .htaccess beside it denies direct access as a second line of defence.
 */

return [
    // --- Database -----------------------------------------------------------
    // Create these in cPanel: MySQL Databases -> new database, new user, then
    // add the user to the database with ALL PRIVILEGES. cPanel prefixes both
    // names with your account, e.g. "dantheman_bookings".
    'db_driver' => 'mysql',
    'db_host'   => 'localhost',
    'db_port'   => 3306,
    'db_name'   => 'REPLACE_account_bookings',
    'db_user'   => 'REPLACE_account_bookings',
    'db_pass'   => 'REPLACE_with_the_database_password',

    // --- Admin --------------------------------------------------------------
    // Generate with make-hash.php, then delete that file. The admin refuses to
    // load while it is still present.
    'admin_password_hash' => 'REPLACE_with_the_generated_hash',

    // --- Notifications ------------------------------------------------------
    // Optional. Each new request is POSTed here as JSON, including an admin_url
    // pointing straight at the record. Point it at a Make or Zapier webhook to
    // get a text message.
    'notify_url' => '',

    // Used to build that admin_url.
    'site_url' => 'https://danthemancan.live',
];
