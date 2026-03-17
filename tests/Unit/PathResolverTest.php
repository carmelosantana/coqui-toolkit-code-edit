<?php

declare(strict_types=1);

use CoquiBot\Toolkits\CodeEdit\Exception\CodeEditException;
use CoquiBot\Toolkits\CodeEdit\Support\PathResolver;

beforeEach(function () {
    $this->tmpDir = sys_get_temp_dir() . '/code-edit-path-test-' . bin2hex(random_bytes(4));
    @mkdir($this->tmpDir, 0755, true);
    $this->tmpDir = realpath($this->tmpDir);
    $this->resolver = new PathResolver($this->tmpDir);
});

afterEach(function () {
    // Clean up temp directory
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($this->tmpDir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($files as $file) {
        $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
    }
    @rmdir($this->tmpDir);
});

test('resolve returns absolute path within workspace', function () {
    file_put_contents($this->tmpDir . '/test.txt', 'hello');
    $resolved = $this->resolver->resolve('test.txt');

    expect($resolved)->toBe($this->tmpDir . '/test.txt');
});

test('resolve blocks directory traversal', function () {
    $this->resolver->resolve('../../etc/passwd');
})->throws(CodeEditException::class, 'escapes workspace boundary');

test('resolve works with subdirectories', function () {
    @mkdir($this->tmpDir . '/sub/dir', 0755, true);
    file_put_contents($this->tmpDir . '/sub/dir/file.php', '<?php');
    $resolved = $this->resolver->resolve('sub/dir/file.php');

    expect($resolved)->toBe($this->tmpDir . '/sub/dir/file.php');
});

test('resolveGlob returns matching files within sandbox', function () {
    file_put_contents($this->tmpDir . '/a.php', '<?php');
    file_put_contents($this->tmpDir . '/b.php', '<?php');
    file_put_contents($this->tmpDir . '/c.txt', 'text');

    $matches = $this->resolver->resolveGlob('*.php');

    expect($matches)->toHaveCount(2);
    foreach ($matches as $match) {
        expect($match)->toEndWith('.php');
    }
});

test('makeRelative converts absolute to relative path', function () {
    file_put_contents($this->tmpDir . '/test.txt', 'hello');
    $relative = $this->resolver->makeRelative($this->tmpDir . '/test.txt');

    expect($relative)->toBe('test.txt');
});

test('workspacePath returns normalized root', function () {
    expect($this->resolver->workspacePath())->toBe(realpath($this->tmpDir));
});
