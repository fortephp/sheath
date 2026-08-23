<?php

declare(strict_types=1);

use Forte\Sheath\Console\EditorCommand;
use Symfony\Component\Console\Tester\CommandTester;

class TestableEditorCommand extends EditorCommand
{
    /** @var list<array<string, mixed>> */
    public array $messages = [];

    /** @var list<array<string, mixed>> */
    public array $outputMessages = [];

    protected function readEditorMessages(): iterable
    {
        yield from $this->messages;
    }

    protected function writeEditorMessage(array $message): void
    {
        $this->outputMessages[] = $message;
    }
}

it('keeps a lint session alive for multiple editor requests', function (): void {
    /** @var TestableEditorCommand $command */
    $command = app()->make(TestableEditorCommand::class);
    $command->setLaravel(app());
    $command->messages = [
        [
            'id' => 'first',
            'filePath' => 'resources/views/first.blade.php',
            'source' => '<img src="first.png">',
        ],
        [
            'id' => 'second',
            'filePath' => 'resources/views/second.blade.php',
            'source' => '<p>Second</p>',
        ],
        ['type' => 'shutdown'],
    ];

    $tester = new CommandTester($command);

    expect($tester->execute([]))->toBe(0)
        ->and($command->outputMessages)->toHaveCount(3)
        ->and($command->outputMessages[0])->toMatchArray([
            'type' => 'ready',
            'protocolVersion' => 1,
            'reportSchemaVersion' => 1,
        ])
        ->and($command->outputMessages[1]['type'])->toBe('result')
        ->and($command->outputMessages[1]['id'])->toBe('first')
        ->and($command->outputMessages[1]['report']['schemaVersion'])->toBe(1)
        ->and($command->outputMessages[1]['report']['results'][0]['filePath'])
        ->toBe('resources/views/first.blade.php')
        ->and($command->outputMessages[2]['type'])->toBe('result')
        ->and($command->outputMessages[2]['id'])->toBe('second');
});

it('reports malformed requests without ending the editor session', function (): void {
    /** @var TestableEditorCommand $command */
    $command = app()->make(TestableEditorCommand::class);
    $command->setLaravel(app());
    $command->messages = [
        ['id' => 'missing-source', 'filePath' => 'resources/views/test.blade.php'],
        [
            'id' => 'valid',
            'filePath' => 'resources/views/test.blade.php',
            'source' => '<p>Valid</p>',
        ],
    ];

    $tester = new CommandTester($command);

    expect($tester->execute([]))->toBe(0)
        ->and($command->outputMessages[1])->toMatchArray([
            'type' => 'error',
            'id' => 'missing-source',
        ])
        ->and($command->outputMessages[2])->toMatchArray([
            'type' => 'result',
            'id' => 'valid',
        ]);
});
