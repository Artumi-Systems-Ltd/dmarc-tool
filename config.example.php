<?php
// config.php
return [
    'imap_host' => 'imap.example.com',
    'imap_user' => 'your-email@example.com',
    'imap_pass' => 'your-password',
    'sqlite_db' => 'dmarc_reports.db',
    'source_folder'='INBOX.dmarc-reports'
    'processed_folder' => 'INBOX.processed-dmarc-reports',
    'failed_folder' => 'INBOX.failed-dmarc-reports',
];
