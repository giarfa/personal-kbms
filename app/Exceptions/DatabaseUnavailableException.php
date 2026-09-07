<?php

namespace App\Exceptions;

use Exception;

class DatabaseUnavailableException extends Exception
{
    public function __construct(
        public readonly string $path,
        public readonly bool $missing,
    ) {
        parent::__construct(
            $missing
                ? "SQLite database file is missing: {$path}"
                : "SQLite database file is unreadable: {$path}"
        );
    }
}
