<?php

declare(strict_types=1);

return [
    'navigation' => [
        'label' => 'Operations',
    ],

    'overview' => [
        'title' => 'Overview',
        'navigation_label' => 'Overview',
        'heading' => 'At a glance',
        'failed_jobs' => 'Failed jobs',
        'failed_jobs_description' => '{0} Nothing has failed|{1} :count job needs attention|[2,*] :count jobs need attention',
        'pending_jobs' => 'Pending jobs',
        'queues_description' => '{0} No queues monitored|{1} Across :count queue|[2,*] Across :count queues',
        'running_batches' => 'Running batches',
        'next_task' => 'Next scheduled task',
        'no_tasks' => 'Nothing scheduled',
        'maintenance' => 'Maintenance mode',
        'down' => 'Down for maintenance',
        'live' => 'Live',
    ],

    'system' => [
        'heading' => 'System',
        'php' => 'PHP',
        'php_description' => 'Running as :sapi',
        'laravel' => 'Laravel',
        'alfakit' => 'AlfaKit',
        'environment' => 'Environment',
        'debug_on' => 'Debug mode is on',
        'debug_on_production' => 'Debug mode is on in production: turn it off',
        'debug_off' => 'Debug mode is off',
        'queue' => 'Queue',
        'cache' => 'Cache',
        'session' => 'Session',
        'mail' => 'Mail',
        'database' => 'Database',
        'disk' => 'Disk space',
        'disk_value' => ':free free',
        'disk_description' => ':percentage free of :total',
    ],

    'failed_jobs' => [
        'title' => 'Failed jobs',
        'navigation_label' => 'Failed jobs',
        'redacted' => '[redacted]',
        'table' => [
            'heading' => 'Failed jobs',
            'unavailable' => 'No failed job store is configured (queue.failed), or its table does not exist.',
            'empty' => [
                'heading' => 'No failed jobs',
                'description' => 'Every queued job ran successfully.',
            ],
        ],
        'columns' => [
            'id' => 'ID',
            'uuid' => 'UUID',
            'job' => 'Job',
            'queue' => 'Queue',
            'connection' => 'Connection',
            'attempts' => 'Attempts',
            'failed_at' => 'Failed',
        ],
        'filters' => [
            'queue' => 'Queue',
            'connection' => 'Connection',
            'job' => 'Job',
            'failed_at' => 'Failed between',
            'from' => 'From',
            'until' => 'Until',
            'from_indicator' => 'Failed from :date',
            'until_indicator' => 'Failed until :date',
        ],
        'details' => [
            'tabs' => 'Details',
            'exception' => 'Exception',
            'payload' => 'Payload',
            'payload_redacted' => 'Values of sensitive keys (passwords, tokens, secrets…) are hidden.',
        ],
        'actions' => [
            'view' => [
                'label' => 'View',
                'modal_heading' => 'Failed job',
                'close' => 'Close',
            ],
            'retry' => [
                'label' => 'Retry',
            ],
            'retry_selected' => [
                'label' => 'Retry selected',
            ],
            'retry_all' => [
                'label' => 'Retry all',
                'modal_heading' => 'Retry every failed job?',
                'modal_description' => 'Every failed job is pushed back onto its queue.',
                'submit' => 'Retry all',
            ],
            'forget' => [
                'label' => 'Forget',
                'modal_heading' => 'Forget this failed job?',
                'modal_description' => 'It is removed from the list and will not be retried.',
                'submit' => 'Forget',
            ],
            'forget_selected' => [
                'label' => 'Forget selected',
                'modal_heading' => 'Forget the selected failed jobs?',
                'modal_description' => 'They are removed from the list and will not be retried.',
            ],
            'flush' => [
                'label' => 'Flush',
                'modal_heading' => 'Delete every failed job?',
                'modal_description' => 'The whole list is deleted. This cannot be undone.',
                'submit' => 'Delete all',
            ],
        ],
        'notifications' => [
            'retried' => '{0} No job was retried|{1} :count job pushed back onto its queue|[2,*] :count jobs pushed back onto their queues',
            'forgotten' => '{0} No job was forgotten|{1} :count failed job forgotten|[2,*] :count failed jobs forgotten',
        ],
    ],

    'queues' => [
        'title' => 'Queues',
        'navigation_label' => 'Queues',
        'unreachable' => 'Unreachable: :message',
        'table' => [
            'heading' => 'Queues',
            'description' => 'Jobs waiting on each queue. Pending jobs are ready to run; delayed jobs wait for their time; reserved jobs are running.',
            'empty' => [
                'heading' => 'No queues to monitor',
                'description' => 'The default queue connection runs jobs immediately (sync), or no database or Redis queue is configured.',
            ],
        ],
        'columns' => [
            'queue' => 'Queue',
            'connection' => 'Connection',
            'pending' => 'Pending',
            'delayed' => 'Delayed',
            'reserved' => 'Reserved',
            'oldest' => 'Oldest pending job',
        ],
        'actions' => [
            'clear' => [
                'label' => 'Clear',
                'modal_heading' => 'Clear the :queue queue?',
                'modal_description' => 'Every job waiting on :queue (:connection) is deleted without running. This cannot be undone.',
                'submit' => 'Clear queue',
            ],
        ],
        'notifications' => [
            'cleared' => '{0} The :queue queue was already empty|{1} :count job deleted from :queue|[2,*] :count jobs deleted from :queue',
        ],
    ],

    'batches' => [
        'title' => 'Batches',
        'navigation_label' => 'Batches',
        'unnamed' => 'Unnamed batch',
        'progress' => ':progress%',
        'processed' => ':processed of :total processed',
        'statuses' => [
            'running' => 'Running',
            'finished' => 'Finished',
            'failed' => 'Has failures',
            'cancelled' => 'Cancelled',
        ],
        'table' => [
            'heading' => 'Job batches',
            'unavailable' => 'Job batching uses the database store and its table (queue.batching); the table does not exist yet.',
            'empty' => [
                'heading' => 'No batches',
                'description' => 'Batches dispatched with Bus::batch() appear here.',
            ],
        ],
        'columns' => [
            'name' => 'Batch',
            'status' => 'Status',
            'progress' => 'Progress',
            'pending' => 'Pending',
            'failed' => 'Failed',
            'created_at' => 'Created',
            'finished_at' => 'Finished',
        ],
        'actions' => [
            'retry' => [
                'label' => 'Retry failed jobs',
                'modal_heading' => 'Retry the failed jobs of this batch?',
                'modal_description' => '{1} :count failed job is pushed back onto its queue.|[2,*] :count failed jobs are pushed back onto their queues.',
                'submit' => 'Retry',
            ],
            'cancel' => [
                'label' => 'Cancel',
                'modal_heading' => 'Cancel this batch?',
                'modal_description' => 'Jobs of :name that have not started yet will not run.',
                'submit' => 'Cancel batch',
            ],
        ],
        'notifications' => [
            'retried' => '{0} No job was retried|{1} :count job pushed back onto its queue|[2,*] :count jobs pushed back onto their queues',
            'cancelled' => 'Batch cancelled',
        ],
    ],

    'schedule' => [
        'title' => 'Scheduler',
        'navigation_label' => 'Scheduler',
        'timezone' => 'Runs in :timezone',
        'never_ran' => 'Not run yet',
        'all_environments' => 'All',
        'seconds' => ':seconds s',
        'last_run_description' => ':time · exit code :code',
        'table' => [
            'heading' => 'Scheduled tasks',
            'description' => 'Times are shown in :timezone.',
            'empty' => [
                'heading' => 'Nothing is scheduled',
                'description' => 'Tasks defined in routes/console.php or with withSchedule() appear here.',
            ],
        ],
        'columns' => [
            'command' => 'Task',
            'type' => 'Type',
            'frequency' => 'Frequency',
            'next_due' => 'Next due',
            'timezone' => 'Timezone',
            'without_overlapping' => 'No overlap',
            'on_one_server' => 'One server',
            'in_background' => 'Background',
            'environments' => 'Environments',
            'last_run' => 'Last run',
            'finished_at' => 'Finished',
            'exit_code' => 'Exit code',
            'runtime' => 'Duration',
            'source' => 'Started by',
        ],
        'types' => [
            'artisan' => 'Artisan',
            'job' => 'Job',
            'closure' => 'Closure',
            'command' => 'Shell',
        ],
        'statuses' => [
            'success' => 'Succeeded',
            'failed' => 'Failed',
            'skipped' => 'Skipped',
        ],
        'sources' => [
            'scheduler' => 'The scheduler',
            'panel' => 'Run now (:user)',
        ],
        'details' => [
            'output' => 'Output',
            'no_output' => 'No output was captured.',
            'output_file' => 'Output file (end)',
        ],
        'actions' => [
            'output' => [
                'label' => 'Last run',
                'close' => 'Close',
            ],
            'run' => [
                'label' => 'Run now',
                'modal_heading' => 'Run this task now?',
                'modal_description' => ':command runs now, while you wait, outside its schedule.',
                'modal_description_queued' => ':command is queued and runs as soon as a worker picks it up.',
                'submit' => 'Run now',
            ],
        ],
        'notifications' => [
            'success' => ':command finished (exit code :code)',
            'failed' => ':command failed (exit code :code)',
            'skipped' => ':command is already running, so it was skipped',
            'queued' => ':command was queued',
            'missing' => 'This task is no longer scheduled',
        ],
    ],

    'cron' => [
        'every_minute' => 'Every minute',
        'every_n_seconds' => 'Every :count seconds',
        'every_n_minutes' => 'Every :count minutes',
        'hourly' => 'Every hour at :minutes',
        'every_n_hours' => 'Every :count hours at :minutes',
        'daily' => 'Every day at :time',
        'weekdays' => 'Weekdays at :time',
        'weekends' => 'Weekends at :time',
        'weekly' => 'Every :days at :time',
        'monthly' => 'Monthly on day :days at :time',
        'quarterly' => 'Quarterly on day :days at :time',
        'yearly' => 'Every year on :date at :time',
    ],

    'logs' => [
        'title' => 'Logs',
        'navigation_label' => 'Logs',
        'file_summary' => ':size · modified :modified',
        'truncated' => 'This entry is longer than the viewer shows; download the file for all of it.',
        'table' => [
            'heading' => 'Log files',
            'missing_directory' => 'The log directory :directory does not exist.',
            'empty' => [
                'heading' => 'No log files',
                'description' => 'Log files written by the application appear here.',
            ],
        ],
        'entries' => [
            'heading' => 'Entries',
            'description' => 'Newest first. Large files are read from the end, one page at a time.',
            'empty' => [
                'heading' => 'No entries',
                'description' => 'No entry matches the filters, or the file is empty.',
            ],
        ],
        'columns' => [
            'name' => 'File',
            'type' => 'Type',
            'size' => 'Size',
            'modified_at' => 'Modified',
            'level' => 'Level',
            'date' => 'Date',
            'message' => 'Message',
            'channel' => 'Channel',
            'context' => 'Context',
        ],
        'types' => [
            'daily' => 'Daily',
            'single' => 'Single',
        ],
        'levels' => [
            'debug' => 'Debug',
            'info' => 'Info',
            'notice' => 'Notice',
            'warning' => 'Warning',
            'error' => 'Error',
            'critical' => 'Critical',
            'alert' => 'Alert',
            'emergency' => 'Emergency',
            'unknown' => 'Unknown',
        ],
        'actions' => [
            'open' => [
                'label' => 'Open',
            ],
            'view' => [
                'label' => 'View',
                'close' => 'Close',
            ],
            'download' => [
                'label' => 'Download',
            ],
            'clear' => [
                'label' => 'Empty file',
                'modal_heading' => 'Empty this log file?',
                'modal_description' => 'Every entry of :file is deleted; the file itself stays. This cannot be undone.',
                'submit' => 'Empty file',
            ],
            'delete' => [
                'label' => 'Delete',
                'modal_heading' => 'Delete this log file?',
                'modal_description' => ':file is deleted. This cannot be undone.',
                'submit' => 'Delete',
            ],
        ],
        'notifications' => [
            'cleared' => ':file emptied',
            'clear_failed' => ':file could not be emptied',
            'deleted' => ':file deleted',
            'delete_failed' => ':file could not be deleted',
        ],
    ],

    'maintenance' => [
        'title' => 'Cache & maintenance',
        'navigation_label' => 'Maintenance',
        'seconds' => 'seconds',
        'status' => [
            'live_heading' => 'The application is live',
            'live_description' => 'Visitors can use the application. Maintenance mode shows them a "be right back" page while you work.',
            'down_heading' => 'The application is in maintenance mode',
            'down_description' => 'Visitors see the maintenance page. You keep access through the bypass link, which sets a cookie in your browser.',
            'down_description_no_secret' => 'Visitors see the maintenance page. It was enabled without a secret, so only the command line can bring it back up.',
            'bypass_url' => 'Bypass link',
        ],
        'table' => [
            'heading' => 'Caches',
            'description' => 'Clear a cache after deploying or changing configuration; rebuild it for faster requests in production.',
        ],
        'columns' => [
            'cache' => 'Cache',
            'command' => 'Command',
            'status' => 'Status',
        ],
        'statuses' => [
            'cached' => 'Cached',
            'empty' => 'Not cached',
        ],
        'tasks' => [
            'cache' => [
                'label' => 'Application cache',
                'description' => 'Everything stored with Cache:: in the default store.',
            ],
            'config' => [
                'label' => 'Configuration',
                'description' => 'The merged configuration files.',
            ],
            'routes' => [
                'label' => 'Routes',
                'description' => 'The compiled route list.',
            ],
            'views' => [
                'label' => 'Views',
                'description' => 'Compiled Blade templates.',
            ],
            'events' => [
                'label' => 'Events',
                'description' => 'Discovered events and listeners.',
            ],
            'compiled' => [
                'label' => 'Compiled services',
                'description' => 'The service and package manifests.',
            ],
            'alfakit' => [
                'label' => 'AlfaKit',
                'description' => 'Discovered panels, resources, pages and widgets.',
            ],
            'schedule' => [
                'label' => 'Scheduler locks',
                'description' => 'Overlap locks of scheduled tasks (frees a task stuck as "running").',
            ],
        ],
        'actions' => [
            'cache' => [
                'label' => 'Cache',
            ],
            'clear' => [
                'label' => 'Clear',
            ],
            'optimize' => [
                'label' => 'Optimize',
                'modal_heading' => 'Cache everything?',
                'modal_description' => 'Runs php artisan optimize: configuration, events, routes, views and AlfaKit discovery are cached. Clear them again after changing any of them.',
                'submit' => 'Optimize',
            ],
            'clear_all' => [
                'label' => 'Clear all caches',
                'modal_heading' => 'Clear every cache?',
                'modal_description' => 'Runs php artisan optimize:clear: the application, configuration, event, route, view, compiled and AlfaKit caches are cleared.',
                'submit' => 'Clear all',
            ],
            'down' => [
                'label' => 'Enable maintenance mode',
                'modal_heading' => 'Put the application into maintenance mode?',
                'modal_description' => 'Visitors see a "be right back" page. You are sent through the bypass link so you keep access.',
                'submit' => 'Enable maintenance mode',
                'secret' => 'Bypass secret',
                'secret_help' => 'Visiting /{secret} lets a browser through. Keep it private.',
                'retry' => 'Retry after',
                'retry_help' => 'Sent as the Retry-After header.',
                'refresh' => 'Refresh after',
                'refresh_help' => 'The maintenance page reloads itself after this many seconds.',
            ],
            'up' => [
                'label' => 'Disable maintenance mode',
                'modal_heading' => 'Bring the application back up?',
                'modal_description' => 'Visitors can use the application again.',
                'submit' => 'Go live',
            ],
        ],
        'notifications' => [
            'optimized' => 'Everything is cached',
            'cleared_all' => 'Every cache was cleared',
            'cleared' => ':cache cleared',
            'cached' => ':cache cached',
            'failed' => 'The command failed',
            'down' => 'Maintenance mode is on',
            'down_body' => 'Keep the bypass link to get back in: :url',
            'up' => 'The application is live again',
        ],
    ],
];
