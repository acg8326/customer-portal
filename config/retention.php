<?php

return [

    // Data retention policy. 0 = keep forever (the default — nothing is ever
    // pruned unless you opt in). When set, `chat:prune` (scheduled daily)
    // permanently deletes conversations whose last activity is older than
    // this many days, including their messages and stored attachments.
    'chat_days' => (int) env('RETENTION_CHAT_DAYS', 0),

    // Soft-deleted (trashed) conversations/projects/skills are purged for
    // good this many days after deletion. 0 = keep trashed rows forever.
    'trash_days' => (int) env('RETENTION_TRASH_DAYS', 30),

];
