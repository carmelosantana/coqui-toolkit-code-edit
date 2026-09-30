<?php

declare(strict_types=1);

namespace CoquiBot\Toolkits\CodeEdit\Support;

use CoquiBot\Toolkits\CodeEdit\Exception\CodeEditException;

/**
 * Shared file I/O helpers with atomic writes and line ending detection.
 *
 * All mutating operations use a temp-file + rename strategy to prevent
 * partial writes from corrupting files.
 */
final readonly class FileOperations
{
    public function __construct(
        private PathResolver $resolver,
    ) {}

    /**
     * Read the full contents of a file.
     *
     * @throws CodeEditException If the file does not exist or cannot be read.
     */
    public function read(string $relativePath): string
    {
        $path = $this->resolver->resolve($relativePath);

        if (!is_file($path)) {
            throw CodeEditException::fileNotFound($relativePath);
        }

        $content = @file_get_contents($path);
        if ($content === false) {
            throw CodeEditException::readFailed($relativePath);
        }

        return $content;
    }

    /**
     * Write content to a file atomically (temp file + rename).
     *
     * @throws CodeEditException If the directory does not exist or writing fails.
     */
    public function write(string $relativePath, string $content): void
    {
        $path = $this->resolver->resolve($relativePath);
        $dir = dirname($path);

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $tmp = $path . '.tmp-' . bin2hex(random_bytes(6));

        if (@file_put_contents($tmp, $content) === false) {
            @unlink($tmp);
            throw CodeEditException::writeFailed($relativePath);
        }

        // Preserve original permissions if file exists
        if (is_file($path)) {
            $perms = fileperms($path);
            if ($perms !== false) {
                @chmod($tmp, $perms);
            }
        }

        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw CodeEditException::writeFailed($relativePath);
        }
    }

    /**
     * Read a file and split into lines, preserving the detected line ending style.
     *
     * @return array{lines: string[], eol: non-empty-string}
     * @throws CodeEditException If the file does not exist or cannot be read.
     */
    public function readLines(string $relativePath): array
    {
        $content = $this->read($relativePath);
        $eol = self::detectEol($content);
        $lines = explode($eol, $content);

        return ['lines' => $lines, 'eol' => $eol];
    }

    /**
     * Join lines with the given EOL and write atomically.
     *
     * @param string[] $lines
     */
    public function writeLines(string $relativePath, array $lines, string $eol = "\n"): void
    {
        $this->write($relativePath, implode($eol, $lines));
    }

    /**
     * Append content to a file. Creates the file if it does not exist
     * (directory must already exist).
     *
     * @throws CodeEditException If the directory does not exist or writing fails.
     */
    public function append(string $relativePath, string $content): int
    {
        $path = $this->resolver->resolve($relativePath);
        $dir = dirname($path);

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $bytes = @file_put_contents($path, $content, FILE_APPEND | LOCK_EX);
        if ($bytes === false) {
            throw CodeEditException::writeFailed($relativePath);
        }

        return $bytes;
    }

    /**
     * Check if a file exists within the workspace.
     */
    public function exists(string $relativePath): bool
    {
        $path = $this->resolver->resolve($relativePath);

        return is_file($path);
    }

    /**
     * Detect the dominant line ending in a string.
     *
     * @return non-empty-string
     */
    public static function detectEol(string $content): string
    {
        $crlf = substr_count($content, "\r\n");
        $lf = substr_count($content, "\n") - $crlf;
        $cr = substr_count($content, "\r") - $crlf;

        if ($crlf >= $lf && $crlf >= $cr && $crlf > 0) {
            return "\r\n";
        }

        if ($cr > $lf && $cr > 0) {
            return "\r";
        }

        return "\n";
    }

    public function resolver(): PathResolver
    {
        return $this->resolver;
    }
}
