<?php

declare(strict_types=1);

use Forte\Sheath\Baselines\Baseline;
use Forte\Sheath\Baselines\BaselineGenerator;
use Forte\Sheath\Exceptions\BaselineException;
use Forte\Sheath\Results\LintResult;
use Forte\Sheath\Results\Position;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Results\Violation;

it('generates a baseline from lint results', function (): void {
    $generator = new BaselineGenerator;

    $results = [
        new LintResult('file1.blade.php', [
            new Violation(
                ruleId: 'a11y-alt-text',
                message: 'Images must have an alt attribute',
                severity: Severity::ERROR,
                filePath: 'file1.blade.php',
                start: new Position(100, 10, 5),
                end: new Position(150, 10, 50)
            ),
        ]),
        new LintResult('file2.blade.php', [
            new Violation(
                ruleId: 'security-csrf-field',
                message: 'Forms must include a CSRF token',
                severity: Severity::ERROR,
                filePath: 'file2.blade.php',
                start: new Position(200, 20, 1),
                end: new Position(250, 20, 50)
            ),
        ]),
    ];

    $baseline = $generator->generate($results, 'test.baseline-gen-test.json');

    expect($baseline->getTotalCount())->toBe(2)
        ->and($baseline->getCountsByRule())->toBe([
            'a11y-alt-text' => 1,
            'security-csrf-field' => 1,
        ])
        ->and($baseline->getFilePaths())->toHaveCount(2);
});

it('generates and saves a baseline', function (): void {
    withTempFile(function (string $path): void {
        $generator = new BaselineGenerator;

        $results = [
            new LintResult('file1.blade.php', [
                new Violation(
                    ruleId: 'a11y-alt-text',
                    message: 'Test message',
                    severity: Severity::ERROR,
                    filePath: 'file1.blade.php',
                    start: new Position(0, 1, 1),
                    end: new Position(10, 1, 10)
                ),
            ]),
        ];

        $baseline = $generator->generateAndSave($results, $path);

        expect(file_exists($path))->toBeTrue();

        $loaded = Baseline::load($path);
        expect($loaded->getTotalCount())->toBe(1);
    }, suffix: '.baseline-gen-test.json');
});

it('throws when generateAndSave cannot write the baseline', function (): void {
    $sandbox = TestViewSandbox::makeInSystemTemp('sheath-baseline-save-failure-');
    $directory = $sandbox->path('baseline-directory');
    mkdir($directory);

    try {
        expect(fn (): Baseline => (new BaselineGenerator)->generateAndSave([], $directory))
            ->toThrow(BaselineException::class);
    } finally {
        $sandbox->cleanup();
    }
});

it('generates consistent hashes for same content', function (): void {
    withTempFile(function (string $testFile): void {
        $generator = new BaselineGenerator;

        $content = "<div>\n<img src=\"test.jpg\">\n</div>";
        file_put_contents($testFile, $content);
        $generator->preloadFileContent($testFile, $content);

        $violation1 = new Violation(
            ruleId: 'a11y-alt-text',
            message: 'Images must have an alt attribute',
            severity: Severity::ERROR,
            filePath: $testFile,
            start: new Position(6, 2, 1),
            end: new Position(25, 2, 20)
        );

        $hash1 = $generator->generateHash($violation1);
        $hash2 = $generator->generateHash($violation1);

        expect($hash1)->toBe($hash2)
            ->and(strlen($hash1))->toBe(32);
    }, suffix: '.blade.php', prefix: 'test-file-');
});

it('generates different hashes for different violations', function (): void {
    $generator = new BaselineGenerator;

    $violation1 = new Violation(
        ruleId: 'a11y-alt-text',
        message: 'Images must have an alt attribute',
        severity: Severity::ERROR,
        filePath: 'file1.blade.php',
        start: new Position(100, 10, 5),
        end: new Position(150, 10, 50)
    );

    $violation2 = new Violation(
        ruleId: 'security-csrf-field',
        message: 'Forms must include a CSRF token',
        severity: Severity::ERROR,
        filePath: 'file1.blade.php',
        start: new Position(100, 10, 5),
        end: new Position(150, 10, 50)
    );

    $hash1 = $generator->generateHash($violation1);
    $hash2 = $generator->generateHash($violation2);

    expect($hash1)->not->toBe($hash2);
});

it('changes the hash when the message or column moves', function (): void {
    $generator = new BaselineGenerator;

    $base = new Violation(
        ruleId: 'a11y-alt-text',
        message: 'Images must have an alt attribute.',
        severity: Severity::ERROR,
        filePath: 'nonexistent.blade.php',
        start: new Position(40, 4, 7),
        end: new Position(53, 4, 20),
    );

    $differentMessage = new Violation($base->ruleId, 'Something else entirely.', $base->severity, $base->filePath, $base->start, $base->end);
    $differentColumn = new Violation($base->ruleId, $base->message, $base->severity, $base->filePath, new Position(42, 4, 9), $base->end);

    expect($generator->generateHash($differentMessage))->not->toBe($generator->generateHash($base))
        ->and($generator->generateHash($differentColumn))->not->toBe($generator->generateHash($base));
});

it('hashes to a full xxh128 digest rather than a prefix', function (): void {
    $violation = new Violation(
        ruleId: 'a11y-alt-text',
        message: 'Images must have an alt attribute.',
        severity: Severity::ERROR,
        filePath: 'nonexistent.blade.php',
        start: new Position(0, 1, 1),
        end: new Position(1, 1, 2),
    );

    expect((new BaselineGenerator)->generateHash($violation))->toMatch('/^[0-9a-f]{32}$/');
});

it('creates a hash generator callback', function (): void {
    $generator = new BaselineGenerator;

    $hashGenerator = $generator->createHashGenerator();

    expect($hashGenerator)->toBeCallable();

    $violation = new Violation(
        ruleId: 'a11y-alt-text',
        message: 'Test',
        severity: Severity::ERROR,
        filePath: 'test.blade.php',
        start: new Position(0, 1, 1),
        end: new Position(10, 1, 10)
    );

    $hash = $hashGenerator($violation);
    expect($hash)->toBeString()
        ->and(strlen((string) $hash))->toBe(32);
});

it('preloads file content for hash generation', function (): void {
    $generator = new BaselineGenerator;

    $content = '<div><img src="test.jpg"></div>';
    $generator->preloadFileContent('preloaded.blade.php', $content);

    $violation = new Violation(
        ruleId: 'a11y-alt-text',
        message: 'Test',
        severity: Severity::ERROR,
        filePath: 'preloaded.blade.php',
        start: new Position(5, 1, 6),
        end: new Position(25, 1, 26)
    );

    $hash1 = $generator->generateHash($violation);

    $generator->clearCache();
    $hash2 = $generator->generateHash($violation);

    expect(strlen($hash1))->toBe(32)
        ->and(strlen($hash2))->toBe(32);
});

it('keeps preloaded falsey content in hash context', function (): void {
    $generator = new BaselineGenerator;

    $violation = new Violation(
        ruleId: 'a11y-alt-text',
        message: 'Test',
        severity: Severity::ERROR,
        filePath: 'zero-content.blade.php',
        start: new Position(0, 1, 1),
        end: new Position(1, 1, 2)
    );

    $generator->preloadFileContent('zero-content.blade.php', '0');
    $hashWithPreload = $generator->generateHash($violation);

    $generator->clearCache();
    $hashWithoutFile = $generator->generateHash($violation);

    expect($hashWithPreload)->not->toBe($hashWithoutFile);
});

it('clears the file content cache', function (): void {
    $generator = new BaselineGenerator;

    $generator->preloadFileContent('cached.blade.php', 'content');
    $generator->clearCache();

    $violation = new Violation(
        ruleId: 'test',
        message: 'Test',
        severity: Severity::ERROR,
        filePath: 'cached.blade.php',
        start: new Position(0, 1, 1),
        end: new Position(10, 1, 10)
    );

    $hash = $generator->generateHash($violation);
    expect($hash)->toBeString();
});

it('updates an existing baseline with current results', function (): void {
    $generator = new BaselineGenerator;

    $initialResults = [
        new LintResult('file.blade.php', [
            new Violation(
                ruleId: 'a11y-alt-text',
                message: 'Message 1',
                severity: Severity::ERROR,
                filePath: 'file.blade.php',
                start: new Position(100, 10, 1),
                end: new Position(150, 10, 50)
            ),
            new Violation(
                ruleId: 'a11y-alt-text',
                message: 'Message 2',
                severity: Severity::ERROR,
                filePath: 'file.blade.php',
                start: new Position(200, 20, 1),
                end: new Position(250, 20, 50)
            ),
            new Violation(
                ruleId: 'security-csrf-field',
                message: 'Message 3',
                severity: Severity::ERROR,
                filePath: 'file.blade.php',
                start: new Position(300, 30, 1),
                end: new Position(350, 30, 50)
            ),
        ]),
    ];

    $baseline = $generator->generate($initialResults, 'test.baseline-gen-test.json');
    expect($baseline->getTotalCount())->toBe(3);

    $currentResults = [
        new LintResult('file.blade.php', [
            new Violation(
                ruleId: 'a11y-alt-text',
                message: 'Message 1',
                severity: Severity::ERROR,
                filePath: 'file.blade.php',
                start: new Position(100, 10, 1),
                end: new Position(150, 10, 50)
            ),
            new Violation(
                ruleId: 'best-practices-button-type',
                message: 'New violation',
                severity: Severity::WARNING,
                filePath: 'file.blade.php',
                start: new Position(400, 40, 1),
                end: new Position(450, 40, 50)
            ),
        ]),
    ];

    $updated = $generator->update($baseline, $currentResults);

    expect($updated->getTotalCount())->toBe(2)
        ->and($updated->getCountsByRule())->toHaveKey('a11y-alt-text')
        ->and($updated->getCountsByRule())->toHaveKey('best-practices-button-type');
});

it('handles empty lint results', function (): void {
    $generator = new BaselineGenerator;

    $baseline = $generator->generate([], 'test.baseline-gen-test.json');

    expect($baseline->isEmpty())->toBeTrue()
        ->and($baseline->getTotalCount())->toBe(0);
});

it('handles results with no violations', function (): void {
    $generator = new BaselineGenerator;

    $results = [
        new LintResult('clean-file.blade.php', []),
        new LintResult('another-clean.blade.php', []),
    ];

    $baseline = $generator->generate($results, 'test.baseline-gen-test.json');

    expect($baseline->isEmpty())->toBeTrue();
});

it('generates hash based on file context', function (): void {
    withTempDir(function (string $dir): void {
        $generator = new BaselineGenerator;

        $file1 = $dir.DIRECTORY_SEPARATOR.'test-file-1.blade.php';
        $file2 = $dir.DIRECTORY_SEPARATOR.'test-file-2.blade.php';

        file_put_contents($file1, "<div>\n<img src=\"a.jpg\">\n<img src=\"b.jpg\">\n</div>");
        file_put_contents($file2, "<span>\n<img src=\"a.jpg\">\n<img src=\"b.jpg\">\n</span>");

        $generator->preloadFileContent($file1, file_get_contents($file1));
        $generator->preloadFileContent($file2, file_get_contents($file2));

        $violation1 = new Violation(
            ruleId: 'a11y-alt-text',
            message: 'Images must have an alt attribute',
            severity: Severity::ERROR,
            filePath: $file1,
            start: new Position(6, 2, 1),
            end: new Position(20, 2, 15)
        );

        $violation2 = new Violation(
            ruleId: 'a11y-alt-text',
            message: 'Images must have an alt attribute',
            severity: Severity::ERROR,
            filePath: $file2,
            start: new Position(7, 2, 1),
            end: new Position(21, 2, 15)
        );

        $hash1 = $generator->generateHash($violation1);
        $hash2 = $generator->generateHash($violation2);

        expect($hash1)->not->toBe($hash2);
    }, prefix: 'sheath-baseline-gen-');
});

it('handles multiple violations in the same file', function (): void {
    $generator = new BaselineGenerator;

    $results = [
        new LintResult('file.blade.php', [
            new Violation(
                ruleId: 'a11y-alt-text',
                message: 'First violation',
                severity: Severity::ERROR,
                filePath: 'file.blade.php',
                start: new Position(0, 1, 1),
                end: new Position(10, 1, 10)
            ),
            new Violation(
                ruleId: 'a11y-alt-text',
                message: 'Second violation',
                severity: Severity::ERROR,
                filePath: 'file.blade.php',
                start: new Position(50, 5, 1),
                end: new Position(60, 5, 10)
            ),
            new Violation(
                ruleId: 'a11y-alt-text',
                message: 'Third violation',
                severity: Severity::ERROR,
                filePath: 'file.blade.php',
                start: new Position(100, 10, 1),
                end: new Position(110, 10, 10)
            ),
        ]),
    ];

    $baseline = $generator->generate($results, 'test.baseline-gen-test.json');

    expect($baseline->getTotalCount())->toBe(3)
        ->and($baseline->getCountsByRule())->toBe(['a11y-alt-text' => 3])
        ->and($baseline->getViolationsForFile('file.blade.php'))->toHaveCount(3);
});

it('generates baseline with various severity levels', function (): void {
    $generator = new BaselineGenerator;

    $results = [
        new LintResult('file.blade.php', [
            new Violation(
                ruleId: 'rule-error',
                message: 'Error',
                severity: Severity::ERROR,
                filePath: 'file.blade.php',
                start: new Position(0, 1, 1),
                end: new Position(10, 1, 10)
            ),
            new Violation(
                ruleId: 'rule-warning',
                message: 'Warning',
                severity: Severity::WARNING,
                filePath: 'file.blade.php',
                start: new Position(20, 2, 1),
                end: new Position(30, 2, 10)
            ),
            new Violation(
                ruleId: 'rule-info',
                message: 'Info',
                severity: Severity::INFO,
                filePath: 'file.blade.php',
                start: new Position(40, 3, 1),
                end: new Position(50, 3, 10)
            ),
        ]),
    ];

    $baseline = $generator->generate($results, 'test.baseline-gen-test.json');

    expect($baseline->getTotalCount())->toBe(3)
        ->and($baseline->getCountsByRule())->toHaveKeys(['rule-error', 'rule-warning', 'rule-info']);
});
