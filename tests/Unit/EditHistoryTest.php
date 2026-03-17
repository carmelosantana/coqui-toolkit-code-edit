<?php

declare(strict_types=1);

use CoquiBot\Toolkits\CodeEdit\Storage\EditHistory;

beforeEach(function () {
    $this->tmpDir = sys_get_temp_dir() . '/code-edit-history-test-' . bin2hex(random_bytes(4));
    @mkdir($this->tmpDir, 0755, true);
    $this->history = new EditHistory($this->tmpDir);
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

test('record returns an edit ID', function () {
    $id = $this->history->record('test.php', 'replace_in_file', 'original content');

    expect($id)->toBeInt();
    expect($id)->toBeGreaterThan(0);
});

test('getBackup returns recorded content', function () {
    $id = $this->history->record('test.php', 'replace_in_file', 'original content');
    $backup = $this->history->getBackup($id);

    expect($backup['id'])->toBe($id);
    expect($backup['file_path'])->toBe('test.php');
    expect($backup['operation'])->toBe('replace_in_file');
    expect($backup['content'])->toBe('original content');
    expect($backup['timestamp'])->not->toBeEmpty();
});

test('getBackup throws for unknown ID', function () {
    $this->history->getBackup(99999);
})->throws(RuntimeException::class, 'not found');

test('getLastEdits returns edits in reverse order', function () {
    $this->history->record('test.php', 'op1', 'content1');
    $this->history->record('test.php', 'op2', 'content2');
    $this->history->record('test.php', 'op3', 'content3');

    $edits = $this->history->getLastEdits('test.php', 2);

    expect($edits)->toHaveCount(2);
    expect($edits[0]['operation'])->toBe('op3');
    expect($edits[1]['operation'])->toBe('op2');
});

test('getLastEdits filters by file path', function () {
    $this->history->record('a.php', 'op1', 'content');
    $this->history->record('b.php', 'op2', 'content');

    $edits = $this->history->getLastEdits('a.php');

    expect($edits)->toHaveCount(1);
    expect($edits[0]['file_path'])->toBe('a.php');
});

test('list returns recent edits', function () {
    $this->history->record('a.php', 'op1', 'c1');
    $this->history->record('b.php', 'op2', 'c2');
    $this->history->record('c.php', 'op3', 'c3');

    $edits = $this->history->list(limit: 10);

    expect($edits)->toHaveCount(3);
    // Most recent first
    expect($edits[0]['file_path'])->toBe('c.php');
});

test('list filters by file path', function () {
    $this->history->record('a.php', 'op1', 'c1');
    $this->history->record('b.php', 'op2', 'c2');

    $edits = $this->history->list('b.php');

    expect($edits)->toHaveCount(1);
    expect($edits[0]['file_path'])->toBe('b.php');
});

test('removeEdit deletes record and backup file', function () {
    $id = $this->history->record('test.php', 'op', 'content');
    $backup = $this->history->getBackup($id);

    $this->history->removeEdit($id);

    expect($this->history->list())->toHaveCount(0);
});

test('prune removes old edits', function () {
    // Record and then manually set old timestamp
    $id = $this->history->record('old.php', 'op', 'content');

    // Pruning with 0 days should remove everything
    $pruned = $this->history->prune(0);

    expect($pruned)->toBe(1);
    expect($this->history->list())->toHaveCount(0);
});

test('record stores metadata', function () {
    $id = $this->history->record('test.php', 'replace_in_file', 'content', [
        'search' => 'foo',
        'replacements' => 3,
    ]);

    $edits = $this->history->list();

    expect($edits[0]['metadata'])->toContain('foo');
    expect($edits[0]['metadata'])->toContain('3');
});
