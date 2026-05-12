<?php

namespace App\Exceptions;

use RuntimeException;

class DuplicateVariantException extends RuntimeException
{
    public function __construct(string $message = 'This variant matches an existing one.')
    {
        parent::__construct($message);
    }
}
