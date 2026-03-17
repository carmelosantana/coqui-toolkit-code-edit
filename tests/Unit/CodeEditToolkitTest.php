<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Contract\ToolkitInterface;
use CoquiBot\Toolkits\CodeEdit\CodeEditToolkit;

test('toolkit implements ToolkitInterface', function () {
    $toolkit = new CodeEditToolkit(workspacePath: sys_get_temp_dir() . '/code-edit-test');

    expect($toolkit)->toBeInstanceOf(ToolkitInterface::class);
});

test('tools returns all eleven tools', function () {
    $toolkit = new CodeEditToolkit(workspacePath: sys_get_temp_dir() . '/code-edit-test');
    $tools = $toolkit->tools();

    expect($tools)->toHaveCount(11);

    $names = array_map(fn(ToolInterface $tool) => $tool->name(), $tools);

    expect($names)->toBe([
        'replace_in_file',
        'insert_before',
        'insert_after',
        'replace_block',
        'remove_lines',
        'extract_lines',
        'batch_replace',
        'append_to_file',
        'write_lines',
        'indent_lines',
        'undo_edit',
    ]);
});

test('each tool implements ToolInterface', function () {
    $toolkit = new CodeEditToolkit(workspacePath: sys_get_temp_dir() . '/code-edit-test');

    foreach ($toolkit->tools() as $tool) {
        expect($tool)->toBeInstanceOf(ToolInterface::class);
    }
});

test('each tool produces a valid function schema', function () {
    $toolkit = new CodeEditToolkit(workspacePath: sys_get_temp_dir() . '/code-edit-test');

    foreach ($toolkit->tools() as $tool) {
        $schema = $tool->toFunctionSchema();
        expect($schema)->toBeArray();
        expect($schema)->toHaveKey('type');
        expect($schema)->toHaveKey('function');
        expect($schema['function'])->toHaveKey('name');
        expect($schema['function'])->toHaveKey('description');
        expect($schema['function'])->toHaveKey('parameters');
        expect($schema['function']['name'])->toBe($tool->name());
    }
});

test('guidelines returns non-empty string with XML tag', function () {
    $toolkit = new CodeEditToolkit(workspacePath: sys_get_temp_dir() . '/code-edit-test');
    $guidelines = $toolkit->guidelines();

    expect($guidelines)->toBeString();
    expect($guidelines)->not->toBeEmpty();
    expect($guidelines)->toContain('<CODE-EDIT-GUIDELINES>');
    expect($guidelines)->toContain('</CODE-EDIT-GUIDELINES>');
});

test('fromEnv creates instance', function () {
    $toolkit = CodeEditToolkit::fromEnv();

    expect($toolkit)->toBeInstanceOf(CodeEditToolkit::class);
    expect($toolkit->tools())->toHaveCount(11);
});
