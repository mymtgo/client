<?php

declare(strict_types=1);

namespace App\Exceptions\Sync;

use RuntimeException;

/**
 * The server refused to enable cloud sync for a draft or sealed deck
 * because this account is on the free tier (spec 2026-09-17).
 */
class LimitedRequiresSupporterException extends RuntimeException {}
