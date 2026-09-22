<?php

/**
 * Copy to config/config.php and fill in for this server.
 * config/config.php is git-ignored - it holds the MIS credentials.
 *
 * Target: PHP 7.4 (FreeBSD jail, Apache 2.4 + mod_php).
 */

declare(strict_types=1);

return [
    'app' => [
        'title' => 'BA (External - New Syllabus) 100 Level - Registration and Examination Application',
        'exam_period' => 'December / January - 2026/2027',
        'closing_date' => '2nd October 2026',
        'office_email' => 'cdce@pdn.ac.lk',
        'office_phone' => '081 2392695',
    ],

    /**
     * The CDCE MIS at http://10.40.129.2/cdcesys/mis_1/ - MySQL 5.5.38.
     *
     * The account must have SELECT on tblstudent and tbl_hold and nothing
     * else; this tool never writes to the MIS.
     */
    'mis' => [
        'dsn' => 'mysql:host=10.40.129.2;port=3306;dbname=dbcdce2;charset=utf8mb4',
        'username' => 'cdce_apply_ro',
        'password' => '',           // <-- set this, then chmod 640
        'timeout_seconds' => 10,

        'table' => 'tblstudent',

        'columns' => [
            'registration_no' => 'reg_no',
            'nic' => 'nic',
            'name_with_initials' => 'name_ini',
            'name_in_full' => 'full_name',
        ],

        /**
         * Restricts the lookup to candidates entitled to this application:
         * on the BA programme, active, and not under an open offence hold.
         *
         * Without it, any student in the MIS could download a 100 Level repeat
         * form. Named parameters are bound, so put values in `params`, never in
         * the SQL text. The subquery is correlated to the outer table by name,
         * so `tblstudent` here must match the `table` key above.
         */
        'eligibility' => [
            'sql' => 'program_id = :program AND status = :status AND NOT EXISTS '
                . '(SELECT 1 FROM tbl_hold h WHERE h.student_id = tblstudent.student_id '
                . 'AND h.type = :hold_type AND h.hold_status = 1)',
            'params' => [
                'program' => 1,
                'status' => 'active',
                'hold_type' => 'offence',
            ],
        ],
    ],

    'rate_limit' => [
        'directory' => dirname(__DIR__) . '/var/rate-limit',
        'max_attempts' => 10,
        'window_seconds' => 900,
    ],

    /**
     * One-time web check (public/setup_check.php), used because the jail has no
     * PHP command line. Set a long random token, run the check, then DELETE
     * public/setup_check.php. Leaving it in place with a token set is a
     * standing invitation to anyone who guesses the token.
     *
     * Generate one on any machine:  openssl rand -hex 32
     *
     * An empty value disables the page outright.
     */
    'setup_token' => '',

    'template' => dirname(__DIR__) . '/templates/ba_100_level_2026.pdf',
    'log_file' => dirname(__DIR__) . '/var/download.log',
];
