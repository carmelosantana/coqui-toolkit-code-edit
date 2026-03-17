<?php

declare(strict_types=1);

use CoquiBot\Toolkits\CodeEdit\Exception\CodeEditException;
use CoquiBot\Toolkits\CodeEdit\Support\FileOperations;
use CoquiBot\Toolkits\CodeEdit\Support\PathResolver;

beforeEach(function () {
    $this->tmpDir = sys_get_temp_dir() . '/code-edit-fileops-test-' . bin2hex(random_bytes(4));
    @mkdir($this->tmpDir, 0755, true);
    $this->files = new FileOperations(new PathResolver($this->tmpDir));
});

afterEach(function () {
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($this->tmpDir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($files as $file) {
        $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
    }
    @rmdir($this->tmpDir);
});

test('read returns file content', function () {
    file_put_contents($this->tmpDir . '/test.txt', 'hello world');
    $content = $this->files->read('test.txt');

    expect($content)->toBe('hello world');
});

test('read throws for missing file', function () {
    $this->files->read('nonexistent.txt');
})->throws(CodeEditException::class, 'File not found');

test('write creates file atomically', function () {
    file_put_contents($this->tmpDir . '/test.txt', 'original');
    $this->files->write('test.txt', 'updated');

    expect(file_get_contents($this->tmpDir . '/test.txt'))->toBe('updated');
});

test('write preserves file permissions', function () {
    file_put_contents($this->tmpDir . '/test.txt', 'original');
    chmod($this->tmpDir . '/test.txt', 0644);
    $this->files->write('test.txt', 'updated');

    $perms = fileperms($this->tmpDir . '/test.txt') & 0777;
    expect($perms)->toBe(0644);
});

test('readLines splits by detected EOL', function () {
    file_put_contents($this->tmpDir . '/unix.txt', "line1\nline2\nline3");
    $data = $this->files->readLines('unix.txt');

    expect($data['lines'])->toBe(['line1', 'line2', 'line3']);
    expect($data['eol'])->toBe("\n");
});

test('readLines detects CRLF', function () {
    file_put_contents($this->tmpDir . '/win.txt', "line1\r\nline2\r\nline3");
    $data = $this->files->readLines('win.txt');

    expect($data['lines'])->toBe(['line1', 'line2', 'line3']);
    expect($data['eol'])->toBe("\r\n");
});

test('writeLines joins with specified EOL', function () {
    $this->files->writeLines('test.txt', ['a', 'b', 'c'], "\r\n");
    $content = file_get_contents($this->tmpDir . '/test.txt');

    expect($content)->toBe("a\r\nb\r\nc");
});

test('append creates file if missing', function () {
    $bytes = $this->files->append('new.txt', 'hello');

    expect($bytes)->toBe(5);
    expect(file_get_contents($this->tmpDir . '/new.txt'))->toBe('hello');
});

test('append adds to existing file', function () {
    file_put_contents($this->tmpDir . '/test.txt', 'first ');
    $this->files->append('test.txt', 'second');

    expect(file_get_contents($this->tmpDir . '/test.txt'))->toBe('first second');
});

test('exists returns true for existing file', function () {
    file_put_contents($this->tmpDir . '/test.txt', 'hello');

    expect($this->files->exists('test.txt'))->toBeTrue();
});

test('exists returns false for missing file', function () {
    expect($this->files->exists('missing.txt'))->toBeFalse();
});

test('detectEol identifies LF', function () {
    expect(FileOperations::detectEol("a\nb\nc"))->toBe("\n");
});

test('detectEol identifies CRLF', function () {
    expect(FileOperations::detectEol("a\r\nb\r\nc"))->toBe("\r\n");
});

test('detectEol defaults to LF for empty content', function () {
    expect(FileOperations::detectEol(''))->toBe("\n");
});
