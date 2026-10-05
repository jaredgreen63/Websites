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
    // Every channel below is optional and they can run together. Leave a value
    // empty to switch that channel off. A booking is stored before any of them
    // run, so a failed notification never loses the lead.

    // Text messages, through Twilio. 'to' takes one number, or several
    // separated by commas. Numbers are in +1XXXXXXXXXX form.
    'sms' => [
        'to'          => '',
        'from'        => '',   // the Twilio number you bought
        'account_sid' => '',   // from the Twilio console
        'auth_token'  => '',   // treat this like a password
    ],

    // Email. Also the free way to get a text: address it to your carrier's
    // gateway instead of an inbox —
    //   Verizon  8645551234@vtext.com
    //   AT&T     8645551234@txt.att.net
    //   T-Mobile 8645551234@tmomail.net
    // Carrier gateways are free but unreliable; Twilio is worth it for leads.
    'notify_email' => '',
    'notify_email_from' => '',   // defaults to no-reply@<your domain>

    // A JSON POST, for Make, Zapier or anything else. The payload carries a
    // ready-made 'message' field, so the scenario needs no message built by
    // hand, and an 'admin_url' pointing straight at the record.
    'notify_url' => '',

    // Used to build that admin_url.
    'site_url' => 'https://danthemancan.live',

    // --- Operational alerts -------------------------------------------------
    // A shared secret the deploy workflow sends with X-Alert-Token so it can
    // tell you a run failed. Generate any long random string and store the
    // same value as the ALERT_TOKEN repository secret on GitHub. Leave empty
    // and the endpoint refuses everything, which is the safe default.
    'alert_token' => '',

    // --- Visit counter ------------------------------------------------------
    // Added to the real count before it is displayed. The database stores only
    // the genuine number of visits, so this offset can be lowered or set to 0
    // at any time and the real figure is still there underneath.
    'visit_offset' => 5000,
];
