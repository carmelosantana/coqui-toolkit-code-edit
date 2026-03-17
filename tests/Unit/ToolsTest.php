<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Enum\ToolResultStatus;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CoquiBot\Toolkits\CodeEdit\CodeEditToolkit;

/**
 * Integration tests for all code-edit tools.
 *
 * Each test creates a temp workspace, runs a tool, and verifies file changes.
 */

beforeEach(function () {
    $this->tmpDir = sys_get_temp_dir() . '/code-edit-tools-test-' . bin2hex(random_bytes(4));
    @mkdir($this->tmpDir, 0755, true);
    $this->toolkit = new CodeEditToolkit(workspacePath: $this->tmpDir);
    $this->tools = [];
    foreach ($this->toolkit->tools() as $tool) {
        $this->tools[$tool->name()] = $tool;
    }
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

// ── replace_in_file ─────────────────────────────────────────────────────────

test('replace_in_file replaces plain text', function () {
    file_put_contents($this->tmpDir . '/test.txt', 'Hello World');

    $result = $this->tools['replace_in_file']->execute([
        'path' => 'test.txt',
        'search' => 'World',
        'replace' => 'PHP',
    ]);

    expect($result->status)->toBe(ToolResultStatus::Success);
    $data = json_decode($result->content, true);
    expect($data['replacements'])->toBe(1);
    expect($data['changed'])->toBeTrue();
    expect(file_get_contents($this->tmpDir . '/test.txt'))->toBe('Hello PHP');
});

test('replace_in_file supports regex', function () {
    file_put_contents($this->tmpDir . '/test.php', 'function myFunc() { }');

    $result = $this->tools['replace_in_file']->execute([
        'path' => 'test.php',
        'search' => 'function (\w+)',
        'replace' => 'function prefix_$1',
        'is_regex' => true,
    ]);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect(file_get_contents($this->tmpDir . '/test.php'))->toBe('function prefix_myFunc() { }');
});

test('replace_in_file returns error for invalid regex', function () {
    file_put_contents($this->tmpDir . '/test.txt', 'content');

    $result = $this->tools['replace_in_file']->execute([
        'path' => 'test.txt',
        'search' => '[invalid',
        'replace' => 'x',
        'is_regex' => true,
    ]);

    expect($result->status)->toBe(ToolResultStatus::Error);
});

test('replace_in_file returns error for missing file', function () {
    $result = $this->tools['replace_in_file']->execute([
        'path' => 'missing.txt',
        'search' => 'x',
        'replace' => 'y',
    ]);

    expect($result->status)->toBe(ToolResultStatus::Error);
    expect($result->content)->toContain('not found');
});

// ── insert_before ───────────────────────────────────────────────────────────

test('insert_before inserts content before matching line', function () {
    file_put_contents($this->tmpDir . '/test.txt', "line1\nline2\nline3");

    $result = $this->tools['insert_before']->execute([
        'path' => 'test.txt',
        'anchor' => 'line2',
        'content' => 'inserted',
    ]);

    expect($result->status)->toBe(ToolResultStatus::Success);
    $data = json_decode($result->content, true);
    expect($data['insertions'])->toBe(1);
    expect(file_get_contents($this->tmpDir . '/test.txt'))->toBe("line1\ninserted\nline2\nline3");
});

test('insert_before respects occurrences limit', function () {
    file_put_contents($this->tmpDir . '/test.txt', "match\nother\nmatch\nmatch");

    $result = $this->tools['insert_before']->execute([
        'path' => 'test.txt',
        'anchor' => 'match',
        'content' => '>>',
        'occurrences' => 2,
    ]);

    $data = json_decode($result->content, true);
    expect($data['insertions'])->toBe(2);
});

test('insert_before with occurrences 0 affects all matches', function () {
    file_put_contents($this->tmpDir . '/test.txt', "match\nother\nmatch\nmatch");

    $result = $this->tools['insert_before']->execute([
        'path' => 'test.txt',
        'anchor' => 'match',
        'content' => '>>',
        'occurrences' => 0,
    ]);

    $data = json_decode($result->content, true);
    expect($data['insertions'])->toBe(3);
});

// ── insert_after ────────────────────────────────────────────────────────────

test('insert_after inserts content after matching line', function () {
    file_put_contents($this->tmpDir . '/test.txt', "line1\nline2\nline3");

    $result = $this->tools['insert_after']->execute([
        'path' => 'test.txt',
        'anchor' => 'line2',
        'content' => 'inserted',
    ]);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect(file_get_contents($this->tmpDir . '/test.txt'))->toBe("line1\nline2\ninserted\nline3");
});

// ── replace_block ───────────────────────────────────────────────────────────

test('replace_block replaces content between markers', function () {
    $content = "header\n// START\nold line 1\nold line 2\n// END\nfooter";
    file_put_contents($this->tmpDir . '/test.txt', $content);

    $result = $this->tools['replace_block']->execute([
        'path' => 'test.txt',
        'start_marker' => '// START',
        'end_marker' => '// END',
        'new_content' => 'new content',
    ]);

    expect($result->status)->toBe(ToolResultStatus::Success);
    $data = json_decode($result->content, true);
    expect($data['blocks_replaced'])->toBe(1);
    expect(file_get_contents($this->tmpDir . '/test.txt'))->toBe("header\nnew content\nfooter");
});

// ── remove_lines ────────────────────────────────────────────────────────────

test('remove_lines removes specified range', function () {
    file_put_contents($this->tmpDir . '/test.txt', "line1\nline2\nline3\nline4\nline5");

    $result = $this->tools['remove_lines']->execute([
        'path' => 'test.txt',
        'from' => 2,
        'to' => 4,
    ]);

    expect($result->status)->toBe(ToolResultStatus::Success);
    $data = json_decode($result->content, true);
    expect($data['lines_removed'])->toBe(3);
    expect($data['total_lines_after'])->toBe(2);
    expect(file_get_contents($this->tmpDir . '/test.txt'))->toBe("line1\nline5");
});

test('remove_lines returns error for invalid range', function () {
    file_put_contents($this->tmpDir . '/test.txt', "line1\nline2");

    $result = $this->tools['remove_lines']->execute([
        'path' => 'test.txt',
        'from' => 5,
        'to' => 3,
    ]);

    expect($result->status)->toBe(ToolResultStatus::Error);
});

// ── extract_lines ───────────────────────────────────────────────────────────

test('extract_lines returns requested range', function () {
    file_put_contents($this->tmpDir . '/test.txt', "line1\nline2\nline3\nline4\nline5");

    $result = $this->tools['extract_lines']->execute([
        'path' => 'test.txt',
        'from' => 2,
        'to' => 4,
    ]);

    expect($result->status)->toBe(ToolResultStatus::Success);
    $data = json_decode($result->content, true);
    expect($data['content'])->toBe("line2\nline3\nline4");
    expect($data['total_lines'])->toBe(5);
    // File should be unchanged
    expect(file_get_contents($this->tmpDir . '/test.txt'))->toBe("line1\nline2\nline3\nline4\nline5");
});

// ── batch_replace ───────────────────────────────────────────────────────────

test('batch_replace modifies matching files', function () {
    file_put_contents($this->tmpDir . '/a.php', 'hello world');
    file_put_contents($this->tmpDir . '/b.php', 'hello universe');
    file_put_contents($this->tmpDir . '/c.txt', 'hello ignore');

    $result = $this->tools['batch_replace']->execute([
        'glob' => '*.php',
        'search' => 'hello',
        'replace' => 'goodbye',
    ]);

    expect($result->status)->toBe(ToolResultStatus::Success);
    $data = json_decode($result->content, true);
    expect($data['files_changed'])->toBe(2);
    expect($data['replacements'])->toBe(2);
    expect(file_get_contents($this->tmpDir . '/a.php'))->toBe('goodbye world');
    expect(file_get_contents($this->tmpDir . '/b.php'))->toBe('goodbye universe');
    // .txt file should be unchanged
    expect(file_get_contents($this->tmpDir . '/c.txt'))->toBe('hello ignore');
});

// ── append_to_file ──────────────────────────────────────────────────────────

test('append_to_file appends to existing file', function () {
    file_put_contents($this->tmpDir . '/test.txt', 'existing ');

    $result = $this->tools['append_to_file']->execute([
        'path' => 'test.txt',
        'content' => 'appended',
    ]);

    expect($result->status)->toBe(ToolResultStatus::Success);
    $data = json_decode($result->content, true);
    expect($data['appended_bytes'])->toBe(8);
    expect(file_get_contents($this->tmpDir . '/test.txt'))->toBe('existing appended');
});

test('append_to_file creates new file', function () {
    $result = $this->tools['append_to_file']->execute([
        'path' => 'new.txt',
        'content' => 'brand new',
    ]);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect(file_get_contents($this->tmpDir . '/new.txt'))->toBe('brand new');
});

// ── write_lines ─────────────────────────────────────────────────────────────

test('write_lines overwrites specified range', function () {
    file_put_contents($this->tmpDir . '/test.txt', "line1\nline2\nline3\nline4\nline5");

    $result = $this->tools['write_lines']->execute([
        'path' => 'test.txt',
        'from' => 2,
        'to' => 3,
        'content' => "new2\nnew3\nnew3b",
    ]);

    expect($result->status)->toBe(ToolResultStatus::Success);
    $data = json_decode($result->content, true);
    expect($data['lines_replaced'])->toBe(2);
    expect($data['total_lines_after'])->toBe(6);
    expect(file_get_contents($this->tmpDir . '/test.txt'))->toBe("line1\nnew2\nnew3\nnew3b\nline4\nline5");
});

// ── indent_lines ────────────────────────────────────────────────────────────

test('indent_lines adds indentation', function () {
    file_put_contents($this->tmpDir . '/test.txt', "line1\nline2\nline3");

    $result = $this->tools['indent_lines']->execute([
        'path' => 'test.txt',
        'from' => 1,
        'to' => 3,
        'direction' => 'indent',
        'size' => 4,
    ]);

    expect($result->status)->toBe(ToolResultStatus::Success);
    $data = json_decode($result->content, true);
    expect($data['lines_affected'])->toBe(3);
    expect(file_get_contents($this->tmpDir . '/test.txt'))->toBe("    line1\n    line2\n    line3");
});

test('indent_lines removes indentation (outdent)', function () {
    file_put_contents($this->tmpDir . '/test.txt', "    line1\n    line2\n    line3");

    $result = $this->tools['indent_lines']->execute([
        'path' => 'test.txt',
        'from' => 1,
        'to' => 3,
        'direction' => 'outdent',
        'size' => 4,
    ]);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect(file_get_contents($this->tmpDir . '/test.txt'))->toBe("line1\nline2\nline3");
});

test('indent_lines skips blank lines', function () {
    file_put_contents($this->tmpDir . '/test.txt', "line1\n\nline3");

    $result = $this->tools['indent_lines']->execute([
        'path' => 'test.txt',
        'from' => 1,
        'to' => 3,
        'direction' => 'indent',
    ]);

    $data = json_decode($result->content, true);
    expect($data['lines_affected'])->toBe(2);
});

// ── undo_edit ───────────────────────────────────────────────────────────────

test('undo_edit restores file after replace', function () {
    file_put_contents($this->tmpDir . '/test.txt', 'original content');

    $this->tools['replace_in_file']->execute([
        'path' => 'test.txt',
        'search' => 'original',
        'replace' => 'modified',
    ]);

    expect(file_get_contents($this->tmpDir . '/test.txt'))->toBe('modified content');

    // List edits to find the ID
    $listResult = $this->tools['undo_edit']->execute(['list_only' => true]);
    expect($listResult->status)->toBe(ToolResultStatus::Success);
    $listData = json_decode($listResult->content, true);
    $editId = $listData['edits'][0]['id'];

    // Undo
    $undoResult = $this->tools['undo_edit']->execute(['edit_id' => $editId]);
    expect($undoResult->status)->toBe(ToolResultStatus::Success);
    expect(file_get_contents($this->tmpDir . '/test.txt'))->toBe('original content');
});

test('undo_edit by file path', function () {
    file_put_contents($this->tmpDir . '/test.txt', 'original');

    $this->tools['replace_in_file']->execute([
        'path' => 'test.txt',
        'search' => 'original',
        'replace' => 'changed',
    ]);

    $result = $this->tools['undo_edit']->execute([
        'file' => 'test.txt',
        'count' => 1,
    ]);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect(file_get_contents($this->tmpDir . '/test.txt'))->toBe('original');
});

test('undo_edit returns error with no parameters', function () {
    $result = $this->tools['undo_edit']->execute([]);

    expect($result->status)->toBe(ToolResultStatus::Error);
});
