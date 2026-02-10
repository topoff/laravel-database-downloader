<?php

return [
    /*
     * The server where the live dumps are located.
     */
    'server' => env('DB_DOWNLOADER_SERVER', 'your-server.com'),

    /*
     * The SSH user for the live server.
     */
    'ssh_user' => env('DB_DOWNLOADER_SSH_USER', 'your-ssh-user'),

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
     * The SSH host for the backup server.
     */
    'backup_ssh_server' => env('DB_DOWNLOADER_BACKUP_SSH_SERVER', 'your-backup-server.com'),

    /*
     * The SSH user for the backup server.
     */
    'backup_ssh_user' => env('DB_DOWNLOADER_BACKUP_SSH_USER', 'your-backup-server-ssh-user'),

    /*
     * The path on the backup server where the backups are stored.
     * {tenant} will be replaced by the tenant name.
     */
    'backup_path_template' => env('DB_DOWNLOADER_BACKUP_PATH_TEMPLATE', '/path/to/your/live-backups/{tenant}/{backup_name}/'),

    /*
     * The local path where the dumps will be stored temporarily.
     */
    'local_path' => database_path('import/dumps/'),

    /*
     * Whether to use the defaults-extra-file for the mysql command.
     */
    'use_local_defaults_extra_file' => true,
];
