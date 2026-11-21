<?php

namespace App\Services\Import;

use RuntimeException;

/**
 * The uploaded file can't be imported as a whole (unreadable, empty, too big).
 * The key points to lang/<locale>/import.php "file.*".
 */
class ImportFileException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $replace
     */
    public function __construct(public readonly string $key, public readonly array $replace = [])
    {
        parent::__construct(__("import.file.{$key}", $replace));
    }
}
