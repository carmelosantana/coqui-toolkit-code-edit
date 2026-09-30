<?php

declare(strict_types=1);

namespace CoquiBot\Toolkits\CodeEdit\Support;

use CoquiBot\Toolkits\CodeEdit\Exception\CodeEditException;

/**
 * Resolves relative paths to absolute paths within the workspace sandbox.
 *
 * All file operations in the code-edit toolkit go through this resolver
 * to prevent directory traversal attacks. Paths are always relative to
 * the workspace root.
 */
final readonly class PathResolver
{
    private string $normalizedRoot;

    public function __construct(
        private string $workspacePath,
    ) {
        $real = realpath($this->workspacePath);
        $this->normalizedRoot = $real !== false
            ? $real
            : rtrim($this->workspacePath, DIRECTORY_SEPARATOR);
    }

    /**
     * Resolve a relative path to an absolute path within the workspace.
     *
     * @throws CodeEditException If the resolved path escapes the workspace boundary.
     */
    public function resolve(string $relativePath): string
    {
        $relative = ltrim($relativePath, "/\\");
        $absolute = $this->normalizedRoot . DIRECTORY_SEPARATOR . $relative;

        // Check the directory portion — the file itself may not exist yet
        $dir = dirname($absolute);
        $realDir = realpath($dir);

        if ($realDir === false) {
            // Directory doesn't exist yet — only '..' traversal can escape the sandbox
            if (str_contains($relative, '..')) {
                throw CodeEditException::pathEscapesSandbox($relativePath);
            }
        } elseif (!str_starts_with($realDir, $this->normalizedRoot)) {
            throw CodeEditException::pathEscapesSandbox($relativePath);
        }

        // If the file exists, verify the full resolved path
        $realPath = realpath($absolute);
        if ($realPath !== false && !str_starts_with($realPath, $this->normalizedRoot)) {
            throw CodeEditException::pathEscapesSandbox($relativePath);
        }

        return $absolute;
    }

    /**
     * Resolve a glob pattern to absolute paths within the workspace.
     *
     * @return string[] Matching file paths (absolute), all within the sandbox.
     */
    public function resolveGlob(string $pattern): array
    {
        $globPattern = $this->normalizedRoot . DIRECTORY_SEPARATOR . ltrim($pattern, "/\\");
        $matches = glob($globPattern, GLOB_NOSORT | GLOB_BRACE) ?: [];

        return array_filter(
            $matches,
            fn(string $path): bool => is_file($path)
                && str_starts_with((string) realpath($path), $this->normalizedRoot),
        );
    }

    /**
     * Convert an absolute path back to a workspace-relative path.
     */
    public function makeRelative(string $absolutePath): string
    {
        $real = realpath($absolutePath) ?: $absolutePath;
        if (str_starts_with($real, $this->normalizedRoot . DIRECTORY_SEPARATOR)) {
            return substr($real, strlen($this->normalizedRoot) + 1);
        }

        return $absolutePath;
    }

    public function workspacePath(): string
    {
        return $this->normalizedRoot;
    }
}
