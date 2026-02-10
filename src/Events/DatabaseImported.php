<?php

namespace Topoff\DatabaseDownloader\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DatabaseImported
{
    use Dispatchable, SerializesModels;

    /**
     * Create a new event instance.
     *
     * @param  string  $tenant  The tenant that was imported.
     * @param  bool  $filesImported  Whether files were also requested for import.
     */
    public function __construct(
        public string $tenant,
        public bool $filesImported = false
    ) {}
}
