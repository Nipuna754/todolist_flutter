<?php

/**
 * Copy to config/config.php and fill in for this server.
 * config/config.php is git-ignored - it holds the MIS credentials.
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
     * The CDCE MIS at http://10.40.129.2/cdcesys/mis_1/.
     *
     * Use an account with SELECT on the student table and nothing else - this
     * tool never writes to the MIS.
     *
     * The identifiers below are placeholders. Replace them with the real table
     * and column names; `php tools/check_mis.php` verifies them before you go
     * live.
     */
    'mis' => [
        'dsn' => 'mysql:host=10.40.129.2;port=3306;dbname=cdcesys;charset=utf8mb4',
        'username' => 'cdce_readonly',
        'password' => '',
        'timeout_seconds' => 10,

        'table' => 'student',

        'columns' => [
            'registration_no' => 'reg_no',
            'nic' => 'nic_no',
            'name_with_initials' => 'name_with_initials',
            'name_in_full' => 'name_in_full',
        ],

        /**
         * Restricts the lookup to candidates entitled to this application.
         * Without it, any student in the MIS could download a 100 Level repeat
         * form. Named parameters are bound, so put values in `params`, never in
         * the SQL text.
         */
        'eligibility' => [
            'sql' => 'course = :course AND level = :level AND academic_year = :year AND status = :status',
            'params' => [
                'course' => 'BA',
                'level' => 100,
                'year' => '2026',
                'status' => 'ACTIVE',
            ],
        ],
    ],

    'rate_limit' => [
        'directory' => dirname(__DIR__) . '/var/rate-limit',
        'max_attempts' => 10,
        'window_seconds' => 900,
    ],

    'template' => dirname(__DIR__) . '/templates/ba_100_level_2026.pdf',
    'log_file' => dirname(__DIR__) . '/var/download.log',
];
