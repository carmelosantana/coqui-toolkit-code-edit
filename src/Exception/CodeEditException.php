<?php

declare(strict_types=1);

namespace CoquiBot\Toolkits\CodeEdit\Exception;

/**
 * Domain exception for code editing operations.
 */
final class CodeEditException extends \RuntimeException
{
    public static function fileNotFound(string $path): self
    {
        return new self(sprintf('File not found: %s', $path));
    }

    public static function pathEscapesSandbox(string $path): self
    {
        return new self(sprintf('Path escapes workspace boundary: %s', $path));
    }

    public static function invalidRegex(string $pattern): self
    {
        return new self(sprintf('Invalid regex pattern: %s', $pattern));
    }

    public static function writeFailed(string $path): self
    {
        return new self(sprintf('Failed to write file: %s', $path));
    }

    public static function directoryNotFound(string $path): self
    {
        return new self(sprintf('Directory does not exist: %s', $path));
    }

    public static function invalidRange(int $from, int $to): self
    {
        return new self(sprintf('Invalid line range: %d–%d (from must be ≤ to)', $from, $to));
    }

    public static function readFailed(string $path): self
    {
        return new self(sprintf('Unable to read file: %s', $path));
    }
}
