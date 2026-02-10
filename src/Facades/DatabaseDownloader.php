<?php

namespace Topoff\DatabaseDownloader\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Topoff\DatabaseDownloader\DatabaseDownloader
 */
class DatabaseDownloader extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Topoff\DatabaseDownloader\DatabaseDownloader::class;
    }
}
