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
     * @param  bool  $filesImported  Whether files were also requested for import.
     */
    public function __construct(
        public bool $filesImported = false
    ) {}
}
