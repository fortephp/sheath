<?php

declare(strict_types=1);

use Forte\Sheath\Caching\ResultCache;
use Forte\Sheath\Results\Fix;
use Forte\Sheath\Results\LintResult;
use Forte\Sheath\Results\Position;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Results\Violation;
use Forte\Sheath\Rules\Accessibility\Content\ImgAltTextRule;
use Forte\Sheath\Rules\Blade\Php\NoDebugRule;

final class CacheWakeupProbe
{
    public static bool $executed = false;

    public function __wakeup(): void
    {
        self::$executed = true;
    }
}

beforeEach(function (): void {
    $this->sandbox = TestViewSandbox::makeInSystemTemp('sheath-result-cache-');
    $this->cachePath = $this->sandbox->path('.test-cache');
});

afterEach(function (): void {
    $this->sandbox->cleanup();
});

it('stores and retrieves lint results', function (): void {
    $cache = new ResultCache($this->cachePath);
    $cache->enable();

    $testFile = $this->sandbox->file('test-file.blade.php', '<div>Test</div>');

    $result = new LintResult($testFile, [
        new Violation(
            ruleId: 'test-rule',
            message: 'Test violation',
            severity: Severity::ERROR,
            filePath: $testFile,
            start: new Position(0, 1, 1),
            end: new Position(10, 1, 10)
        ),
    ]);

    $cache->put($testFile, $result);

    expect($cache->has($testFile))->toBeTrue();

    $cached = $cache->get($testFile);

    expect($cached)->toBeInstanceOf(LintResult::class)
        ->and($cached->filePath)->toBe($testFile)
        ->and($cached->violations)->toHaveCount(1)
        ->and($cached->violations[0]->ruleId)->toBe('test-rule')
        ->and($cached->violations[0]->message)->toBe('Test violation');
});

it('can cache content already read by the linter', function (): void {
    $cache = new ResultCache($this->cachePath);
    $cache->enable();

    $original = '<p>original</p>';
    $testFile = $this->sandbox->file('known-source.blade.php', $original);

    $result = new LintResult($testFile, []);
    $cache->put($testFile, $result, $original);

    file_put_contents($testFile, '<p>changed</p>');
    expect($cache->has($testFile))->toBeFalse()
        ->and($cache->has($testFile, $original))->toBeTrue();

    file_put_contents($testFile, $original);
    expect($cache->has($testFile))->toBeTrue()
        ->and($cache->get($testFile))->toEqual($result);
});

it('uses the lint result source hash when the file changes before cache insertion', function (): void {
    $original = '<p>original</p>';
    $changed = '<p>changed</p>';
    $testFile = $this->sandbox->file('parallel-race.blade.php', $original);

    $cache = new ResultCache($this->cachePath);
    $cache->enable();

    file_put_contents($testFile, $changed);
    $cache->put($testFile, new LintResult(
        $testFile,
        [],
        sourceHash: hash('xxh128', $original),
    ));

    expect($cache->has($testFile))->toBeFalse();

    file_put_contents($testFile, $original);

    expect($cache->has($testFile))->toBeTrue();
});

it('infers an unhashed result source only once when the same result is stored repeatedly', function (): void {
    $original = '<p>original</p>';
    $changed = '<p>changed</p>';
    $testFile = $this->sandbox->file('repeated-result.blade.php', $original);
    $result = new LintResult($testFile, []);

    $cache = (new ResultCache($this->cachePath))->enable();
    $cache->put($testFile, $result);

    file_put_contents($testFile, $changed);
    $cache->put($testFile, $result);

    expect($cache->has($testFile))->toBeFalse();

    file_put_contents($testFile, $original);

    expect($cache->has($testFile))->toBeTrue();
});

it('invalidates cache when file is modified', function (): void {
    $cache = new ResultCache($this->cachePath);
    $cache->enable();

    $testFile = $this->sandbox->file('test-file.blade.php', '<div>Test</div>');

    $result = new LintResult($testFile, []);
    $cache->put($testFile, $result);

    expect($cache->has($testFile))->toBeTrue();

    file_put_contents($testFile, '<div>Modified</div>');

    expect($cache->has($testFile))->toBeFalse();
});

it('uses the cache context when checking cache hits', function (): void {
    $testFile = $this->sandbox->file('test-file.blade.php', '<div>Test</div>');

    $cache = new ResultCache($this->cachePath);
    $cache->setContext(['rules' => ['a11y-alt-text' => 'warning']])->enable();
    $cache->put($testFile, new LintResult($testFile, []));

    expect($cache->has($testFile))->toBeTrue();

    $cache->setContext(['rules' => ['a11y-alt-text' => 'error']]);

    expect($cache->has($testFile))->toBeFalse();
});

it('rejects cache contexts that cannot be encoded deterministically', function (): void {
    $cache = new ResultCache($this->cachePath);

    expect(fn (): ResultCache => $cache->setContext(['option' => "\xB1\x31"]))
        ->toThrow(JsonException::class);
});

it('uses rule inventory changes in the cache context', function (): void {
    $testFile = $this->sandbox->file('test-file.blade.php', '<div>Test</div>');

    $cache = new ResultCache($this->cachePath);
    $cache->setContext(['rules' => ['a11y-alt-text' => ImgAltTextRule::class]])->enable();
    $cache->put($testFile, new LintResult($testFile, []));

    expect($cache->has($testFile))->toBeTrue();

    $cache->setContext([
        'rules' => [
            'a11y-alt-text' => ImgAltTextRule::class,
            'blade-no-debug' => NoDebugRule::class,
        ],
    ]);

    expect($cache->has($testFile))->toBeFalse();
});

it('persists cache entries with their context', function (): void {
    $testFile = $this->sandbox->file('test-file.blade.php', '<div>Test</div>');

    $cache1 = new ResultCache($this->cachePath);
    $cache1->setContext(['rules' => ['a11y-alt-text' => 'warning']])->enable();
    $cache1->put($testFile, new LintResult($testFile, []));
    $cache1->save();

    $cache2 = new ResultCache($this->cachePath);
    $cache2->setContext(['rules' => ['a11y-alt-text' => 'warning']])->enable();

    expect($cache2->has($testFile))->toBeTrue();

    $cache3 = new ResultCache($this->cachePath);
    $cache3->setContext(['rules' => ['a11y-alt-text' => 'error']])->enable();

    expect($cache3->has($testFile))->toBeFalse();
});

it('prunes entries for files that have been deleted or renamed', function (): void {
    $deletedFile = $this->sandbox->file('deleted.blade.php', '<p>deleted</p>');
    $renamedFile = $this->sandbox->file('old-name.blade.php', '<p>renamed</p>');

    $cache = new ResultCache($this->cachePath);
    $cache->enable();
    $cache->put($deletedFile, new LintResult($deletedFile, []));
    $cache->put($renamedFile, new LintResult($renamedFile, []));
    $cache->save();

    unlink($deletedFile);
    rename($renamedFile, $this->sandbox->path('new-name.blade.php'));

    $reloaded = new ResultCache($this->cachePath);
    $reloaded->enable();
    $reloaded->save();

    $payload = json_decode((string) file_get_contents($this->cachePath), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['entries'])->not->toHaveKey($deletedFile)
        ->and($payload['entries'])->not->toHaveKey($renamedFile);
});

it('persists cache to disk', function (): void {
    $testFile = $this->sandbox->file('test-file.blade.php', '<div>Test</div>');

    $cache1 = new ResultCache($this->cachePath);
    $cache1->enable();

    $result = new LintResult($testFile, [
        new Violation(
            ruleId: 'test-rule',
            message: 'Test violation',
            severity: Severity::WARNING,
            filePath: $testFile,
            start: new Position(0, 1, 1),
            end: new Position(5, 1, 5)
        ),
    ]);

    $cache1->put($testFile, $result);
    $cache1->save();

    $cache2 = new ResultCache($this->cachePath);
    $cache2->enable();

    expect($cache2->has($testFile))->toBeTrue();

    $cached = $cache2->get($testFile);
    expect($cached)->toBeInstanceOf(LintResult::class)
        ->and($cached->violations)->toHaveCount(1);
});

it('persists a versioned JSON cache instead of serialized PHP', function (): void {
    $testFile = $this->sandbox->file('json-cache.blade.php', '<div>Test</div>');

    $cache = new ResultCache($this->cachePath);
    $cache->enable();
    $cache->put($testFile, new LintResult($testFile, []));
    $cache->save();

    $contents = file_get_contents($this->cachePath);
    $payload = json_decode((string) $contents, true, flags: JSON_THROW_ON_ERROR);

    expect($contents)->toStartWith('{')
        ->and($payload['version'])->toBe(1)
        ->and($payload['entries'])->toHaveKey($testFile);
});

it('ignores malformed and unsupported cache payloads', function (string $contents): void {
    file_put_contents($this->cachePath, $contents);

    $cache = new ResultCache($this->cachePath);
    $cache->enable();

    expect($cache->get('anything'))->toBeNull();
})->with([
    'malformed JSON' => '{not-json',
    'unsupported version' => '{"version":999,"entries":[]}',
    'invalid entries collection' => '{"version":1,"entries":"invalid"}',
]);

it('ignores malformed cache entries while loading valid ones', function (): void {
    $validFile = $this->sandbox->file('valid.blade.php', '<p>valid</p>');
    $sourceHash = hash('xxh128', '<p>valid</p>');
    $contextualHash = hash('xxh128', 'default'."\0".$sourceHash);
    $payload = [
        'version' => 1,
        'entries' => [
            $validFile => [
                'source_hash' => $contextualHash,
                'data' => (new LintResult($validFile, []))->toArray(),
            ],
            'missing-source-hash' => ['data' => []],
            'missing-data' => ['source_hash' => 'hash'],
        ],
    ];
    file_put_contents($this->cachePath, json_encode($payload, JSON_THROW_ON_ERROR));

    $cache = new ResultCache($this->cachePath);
    $cache->enable();

    expect($cache->has($validFile))->toBeTrue()
        ->and($cache->get($validFile))->toBeInstanceOf(LintResult::class)
        ->and($cache->get('missing-source-hash'))->toBeNull()
        ->and($cache->get('missing-data'))->toBeNull();
});

it('returns null for non-existent cache entry', function (): void {
    $cache = new ResultCache($this->cachePath);
    $cache->enable();

    expect($cache->get('non-existent.blade.php'))->toBeNull();
});

it('does not treat unreadable existing paths as cache hits', function (): void {
    $cache = new ResultCache($this->cachePath);
    $cache->enable();

    $path = $this->sandbox->path('test-cache-dir-'.uniqid());
    mkdir($path);

    $cache->put($path, new LintResult($path, []));

    expect($cache->has($path))->toBeFalse()
        ->and($cache->get($path))->toBeNull();
});

it('can clear cache', function (): void {
    $cache = new ResultCache($this->cachePath);
    $cache->enable();

    $testFile = $this->sandbox->file('test-file.blade.php', '<div>Test</div>');

    $result = new LintResult($testFile, []);
    $cache->put($testFile, $result);
    $cache->save();

    expect(file_exists($this->cachePath))->toBeTrue();

    $cache->clear();

    expect($cache->has($testFile))->toBeFalse()
        ->and(file_exists($this->cachePath))->toBeFalse();
});

it('handles multiple files in cache', function (): void {
    $cache = new ResultCache($this->cachePath);
    $cache->enable();

    $file1 = $this->sandbox->file('test-file-1.blade.php', '<div>Test 1</div>');
    $file2 = $this->sandbox->file('test-file-2.blade.php', '<div>Test 2</div>');

    $result1 = new LintResult($file1, []);
    $result2 = new LintResult($file2, []);

    $cache->put($file1, $result1);
    $cache->put($file2, $result2);

    expect($cache->has($file1))->toBeTrue()
        ->and($cache->has($file2))->toBeTrue();
});

it('does not store when disabled', function (): void {
    $cache = new ResultCache($this->cachePath);

    $testFile = $this->sandbox->file('test-file.blade.php', '<div>Test</div>');

    $result = new LintResult($testFile, []);
    $cache->put($testFile, $result);

    expect($cache->has($testFile))->toBeFalse();
});

it('does not save when disabled', function (): void {
    $cache = new ResultCache($this->cachePath);

    $cache->save();

    expect(file_exists($this->cachePath))->toBeFalse();
});

it('never deserializes PHP objects from a cache file', function (): void {
    CacheWakeupProbe::$executed = false;
    file_put_contents($this->cachePath, serialize(new CacheWakeupProbe));

    (new ResultCache($this->cachePath))->enable();

    expect(CacheWakeupProbe::$executed)->toBeFalse();
});

it('round trips invalid UTF-8 fix bytes losslessly', function (): void {
    $cache = (new ResultCache($this->cachePath))->enable();
    $path = $this->sandbox->file('binary-fix.blade.php', '<div></div>');
    $result = new LintResult($path, [new Violation(
        ruleId: 'binary-fix',
        message: 'Binary fix.',
        severity: Severity::WARNING,
        filePath: $path,
        start: new Position(0, 1, 1),
        end: new Position(1, 1, 2),
        fix: new Fix(0, 1, "'\xFF'"),
    )]);

    $cache->put($path, $result);

    expect($cache->save())->toBeTrue();

    $reloaded = (new ResultCache($this->cachePath))->enable()->get($path);

    expect($reloaded)->not->toBeNull()
        ->and($reloaded->violations[0]->fix?->replacement)->toBe("'\xFF'");
});
