<?php

declare(strict_types=1);

return [
    /*
     * The server where the live dumps are located.
     */
    'live_server' => env('DB_DOWNLOADER_SERVER', 'your-server.com'),

    /*
     * The SSH user for the live server.
     */
    'live_ssh_user' => env('DB_DOWNLOADER_SSH_USER', 'your-ssh-user'),

    /*
     * The path to the MySQL config file on the live server.
     */
    'live_mysql_config_path' => env('DB_DOWNLOADER_MYSQL_CONFIG_PATH', '~/mysql-login.cnf'),

    /*
     * The database.connections.key to use
     */
    'mysql_connection' => env('MYSQL_CONNECTION', 'mysql'),

    /*
     * The server where the live dumps are located.
     */
    'staging_server' => env('DB_DOWNLOADER_STAGING_SERVER', 'your-server.com'),

    /*
     * The SSH user for the live server.
     */
    'staging_ssh_user' => env('DB_DOWNLOADER_STAGING_SSH_USER', 'your-ssh-user'),

    /*
     * The path to the MySQL config file on the staging server.
     */
    'staging_mysql_config_path' => env('DB_DOWNLOADER_STAGING_MYSQL_CONFIG_PATH', '~/mysql-login.cnf'),

    /*
     * The SSH host for the backup server.
     */
    'backup_ssh_server' => env('DB_DOWNLOADER_BACKUP_SSH_SERVER', 'your-backup-server.com'),

    /*
     * The SSH user for the backup server.
     */
    'backup_ssh_user' => env('DB_DOWNLOADER_BACKUP_SSH_USER', 'your-backup-server-ssh-user'),

    /*
     * The path on the backup server where the backups are stored.
     * {backup_name} will be replaced by the backup name from config.
     */
    'backup_path_template' => env('DB_DOWNLOADER_BACKUP_PATH_TEMPLATE', '/path/to/your/live-backups/{backup_name}/'),

    /*
     * The local path where the dumps will be stored temporarily.
     */
    'local_path' => database_path('import/dumps/'),

    /*
     * Whether to use the defaults-extra-file for the mysql command.
     */
    'use_local_defaults_extra_file' => true,

    /*
     * Overwrite contact data (email, phone, street address) with placeholders right
     * after the import, so no real personal data ends up on development machines.
     * On by default everywhere except in the "staging" environment.
     */
    'anonymize' => env('DB_DOWNLOADER_ANONYMIZE', env('APP_ENV') !== 'staging'),

    /*
     * The domain of the placeholder email addresses.
     */
    'anonymize_email_domain' => 'example.test',

    /*
     * Columns are detected by name (email, *_email, phone, *_phone, mobile, address,
     * street, ...). Add columns the detection cannot know, or exclude wrong matches:
     * 'table' => ['column' => 'email'|'phone'|'address'|'contact'|false]
     * "contact" holds either an email or a phone number; false excludes the column.
     */
    'anonymize_columns' => [],

    /*
     * Email addresses ending in one of these domains are kept (e.g. your own team).
     */
    'anonymize_keep_email_domains' => [],

    /*
     * Rows matching the SQL condition are kept entirely: 'table' => 'is_admin = 1'
     */
    'anonymize_keep_rows' => [],
];
