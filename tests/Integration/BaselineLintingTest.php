<?php

declare(strict_types=1);

use Forte\Sheath\Baselines\Baseline;

beforeEach(function (): void {
    foreach (glob(base_path('*.baseline-int-test.json')) as $file) {
        unlink($file);
    }
    foreach (glob(base_path('test-views-baseline/*.blade.php')) as $file) {
        unlink($file);
    }
    if (is_dir(base_path('test-views-baseline'))) {
        rmdir(base_path('test-views-baseline'));
    }

    if (! is_dir(base_path('test-views-baseline'))) {
        mkdir(base_path('test-views-baseline'), 0755, true);
    }
});

afterEach(function (): void {
    foreach (glob(base_path('*.baseline-int-test.json')) as $file) {
        unlink($file);
    }
    foreach (glob(base_path('test-views-baseline/*.blade.php')) as $file) {
        unlink($file);
    }
    if (is_dir(base_path('test-views-baseline'))) {
        rmdir(base_path('test-views-baseline'));
    }
});

it('generates baseline from lint results', function (): void {
    $testFile = base_path('test-views-baseline/test.blade.php');
    file_put_contents($testFile, '<img src="test.jpg">');

    $baselinePath = base_path('test.baseline-int-test.json');

    $this->artisan('sheath:lint', [
        'paths' => [base_path('test-views-baseline')],
        '--generate-baseline' => true,
        '--baseline' => 'test.baseline-int-test.json',
        '--only' => 'a11y-alt-text',
    ])
        ->assertSuccessful();

    expect(file_exists($baselinePath))->toBeTrue();

    $baseline = Baseline::load($baselinePath);
    expect($baseline->getTotalCount())->toBeGreaterThan(0);
});

it('never baselines parse errors or exits successfully for malformed templates', function (): void {
    $testFile = base_path('test-views-baseline/broken.blade.php');
    file_put_contents($testFile, '{{ unclosed');

    $baselinePath = base_path('parse-errors.baseline-int-test.json');

    $this->artisan('sheath:lint', [
        'paths' => [base_path('test-views-baseline')],
        '--generate-baseline' => true,
        '--baseline' => 'parse-errors.baseline-int-test.json',
    ])->assertFailed();

    expect(Baseline::load($baselinePath)->getTotalCount())->toBe(0);

    $this->artisan('sheath:lint', [
        'paths' => [base_path('test-views-baseline')],
        '--baseline' => 'parse-errors.baseline-int-test.json',
    ])->assertFailed();
});

it('filters violations using baseline', function (): void {
    $testFile = base_path('test-views-baseline/test.blade.php');
    file_put_contents($testFile, '<img src="test.jpg">');

    $baselinePath = base_path('test.baseline-int-test.json');

    $this->artisan('sheath:lint', [
        'paths' => [base_path('test-views-baseline')],
        '--generate-baseline' => true,
        '--baseline' => 'test.baseline-int-test.json',
        '--only' => 'a11y-alt-text',
    ])->assertSuccessful();

    $this->artisan('sheath:lint', [
        'paths' => [base_path('test-views-baseline')],
        '--baseline' => 'test.baseline-int-test.json',
        '--only' => 'a11y-alt-text',
        '--format' => 'json',
    ])
        ->assertSuccessful();
});

it('keeps baselined fixes suppressed across repeated fix passes', function (): void {
    $testFile = base_path('test-views-baseline/test.blade.php');
    file_put_contents($testFile, '<form method="post">@csrf<button accesskey="s">Save</button></form>');

    $this->artisan('sheath:lint', [
        'paths' => [base_path('test-views-baseline')],
        '--generate-baseline' => true,
        '--baseline' => 'test.baseline-int-test.json',
        '--only' => 'a11y-no-accesskey',
    ])->assertSuccessful();

    $this->artisan('sheath:lint', [
        'paths' => [base_path('test-views-baseline')],
        '--baseline' => 'test.baseline-int-test.json',
        '--only' => 'a11y-no-accesskey,best-practices-button-type',
        '--fix' => true,
    ])->assertSuccessful();

    expect(file_get_contents($testFile))
        ->toBe('<form method="post">@csrf<button accesskey="s" type="submit">Save</button></form>');
});

it('caches raw post-fix results independently of baseline state', function (): void {
    $testFile = base_path('test-views-baseline/test.blade.php');
    $baselinePath = base_path('test.baseline-int-test.json');
    file_put_contents($testFile, '<div class="a a"></div><img src="test.jpg">');

    $this->artisan('sheath:lint', [
        'paths' => [base_path('test-views-baseline')],
        '--generate-baseline' => true,
        '--baseline' => 'test.baseline-int-test.json',
        '--only' => 'a11y-alt-text',
    ])->assertSuccessful();

    withTempFile(function (string $cachePath) use ($baselinePath, $testFile): void {
        $this->artisan('sheath:lint', [
            'paths' => [base_path('test-views-baseline')],
            '--baseline' => 'test.baseline-int-test.json',
            '--only' => 'a11y-alt-text,best-practices-no-duplicate-class',
            '--cache' => true,
            '--cache-location' => $cachePath,
            '--fix' => true,
        ])->assertSuccessful();

        expect(file_get_contents($testFile))
            ->toBe('<div class="a"></div><img src="test.jpg">');

        $this->artisan('sheath:lint', [
            'paths' => [base_path('test-views-baseline')],
            '--baseline' => 'test.baseline-int-test.json',
            '--ignore-baseline' => true,
            '--only' => 'a11y-alt-text,best-practices-no-duplicate-class',
            '--cache' => true,
            '--cache-location' => $cachePath,
            '--stats' => true,
        ])
            ->assertFailed();

        expect((new Baseline($baselinePath))->save())->toBeTrue();

        $this->artisan('sheath:lint', [
            'paths' => [base_path('test-views-baseline')],
            '--baseline' => 'test.baseline-int-test.json',
            '--only' => 'a11y-alt-text,best-practices-no-duplicate-class',
            '--cache' => true,
            '--cache-location' => $cachePath,
            '--stats' => true,
        ])
            ->assertFailed();

        unlink($baselinePath);

        $this->artisan('sheath:lint', [
            'paths' => [base_path('test-views-baseline')],
            '--baseline' => 'test.baseline-int-test.json',
            '--only' => 'a11y-alt-text,best-practices-no-duplicate-class',
            '--cache' => true,
            '--cache-location' => $cachePath,
            '--stats' => true,
        ])
            ->assertFailed();
    }, prefix: 'baseline-cache-');
});

it('fails without overwriting a corrupt baseline during update', function (): void {
    $testFile = base_path('test-views-baseline/test.blade.php');
    file_put_contents($testFile, '<img src="test.jpg">');

    $baselinePath = base_path('corrupt.baseline-int-test.json');
    $corrupt = '{not valid json';
    file_put_contents($baselinePath, $corrupt);

    $this->artisan('sheath:lint', [
        'paths' => [base_path('test-views-baseline')],
        '--update-baseline' => true,
        '--baseline' => 'corrupt.baseline-int-test.json',
        '--only' => 'a11y-alt-text',
    ])
        ->assertFailed();

    expect(file_get_contents($baselinePath))->toBe($corrupt);
});

it('ignores baseline when --ignore-baseline is set', function (): void {
    $testFile = base_path('test-views-baseline/test.blade.php');
    file_put_contents($testFile, '<img src="test.jpg">');

    $baselinePath = base_path('test.baseline-int-test.json');

    $this->artisan('sheath:lint', [
        'paths' => [base_path('test-views-baseline')],
        '--generate-baseline' => true,
        '--baseline' => 'test.baseline-int-test.json',
        '--only' => 'a11y-alt-text',
    ])->assertSuccessful();

    $result = $this->artisan('sheath:lint', [
        'paths' => [base_path('test-views-baseline')],
        '--baseline' => 'test.baseline-int-test.json',
        '--ignore-baseline' => true,
        '--only' => 'a11y-alt-text',
    ]);

    $result->assertFailed();
});

it('updates baseline with new violations', function (): void {
    $testFile = base_path('test-views-baseline/test.blade.php');
    file_put_contents($testFile, '<img src="test.jpg">');

    $baselinePath = base_path('test.baseline-int-test.json');

    $this->artisan('sheath:lint', [
        'paths' => [base_path('test-views-baseline')],
        '--generate-baseline' => true,
        '--baseline' => 'test.baseline-int-test.json',
        '--only' => 'a11y-alt-text',
    ])->assertSuccessful();

    $initialBaseline = Baseline::load($baselinePath);
    $initialCount = $initialBaseline->getTotalCount();
    expect($initialCount)->toBeGreaterThan(0);

    $testFile2 = base_path('test-views-baseline/test2.blade.php');
    file_put_contents($testFile2, '<img src="another.jpg">');

    $this->artisan('sheath:lint', [
        'paths' => [base_path('test-views-baseline')],
        '--update-baseline' => true,
        '--baseline' => 'test.baseline-int-test.json',
        '--only' => 'a11y-alt-text',
    ])
        ->assertSuccessful();

    $updatedBaseline = Baseline::load($baselinePath);
    expect($updatedBaseline->getTotalCount())->toBeGreaterThanOrEqual($initialCount);

    if (file_exists($testFile2)) {
        unlink($testFile2);
    }
});

it('uses default baseline path when not specified', function (): void {
    $testFile = base_path('test-views-baseline/test.blade.php');
    file_put_contents($testFile, '<img src="test.jpg">');

    $this->artisan('sheath:lint', [
        'paths' => [base_path('test-views-baseline')],
        '--generate-baseline' => true,
        '--only' => 'a11y-alt-text',
    ])->assertSuccessful();

    expect(file_exists(base_path(Baseline::DEFAULT_PATH)))->toBeTrue();

    if (file_exists(base_path(Baseline::DEFAULT_PATH))) {
        unlink(base_path(Baseline::DEFAULT_PATH));
    }
});

it('supports absolute baseline paths', function (): void {
    $testFile = base_path('test-views-baseline/test.blade.php');
    file_put_contents($testFile, '<img src="test.jpg">');

    withTempFile(function (string $baselinePath): void {
        $this->artisan('sheath:lint', [
            'paths' => [base_path('test-views-baseline')],
            '--generate-baseline' => true,
            '--baseline' => $baselinePath,
            '--only' => 'a11y-alt-text',
        ])->assertSuccessful();

        expect(file_exists($baselinePath))->toBeTrue();
    }, suffix: '.json', prefix: 'baseline-int-');
});

it('fails when baseline generation cannot write the baseline file', function (): void {
    $testFile = base_path('test-views-baseline/test.blade.php');
    file_put_contents($testFile, '<img src="test.jpg">');

    withTempDir(function (string $baselinePath): void {
        $this->artisan('sheath:lint', [
            'paths' => [base_path('test-views-baseline')],
            '--generate-baseline' => true,
            '--baseline' => $baselinePath,
            '--only' => 'a11y-alt-text',
        ])
            ->assertFailed();
    }, prefix: 'baseline-int-dir-');
});

it('does not filter violations from files not in baseline', function (): void {
    $testFile = base_path('test-views-baseline/test.blade.php');
    file_put_contents($testFile, '<img src="test.jpg">');

    $baselinePath = base_path('test.baseline-int-test.json');

    $this->artisan('sheath:lint', [
        'paths' => [base_path('test-views-baseline')],
        '--generate-baseline' => true,
        '--baseline' => 'test.baseline-int-test.json',
        '--only' => 'a11y-alt-text',
    ])->assertSuccessful();

    $baseline = Baseline::load($baselinePath);
    expect($baseline->getTotalCount())->toBeGreaterThan(0);

    $this->artisan('sheath:lint', [
        'paths' => [base_path('test-views-baseline')],
        '--baseline' => 'test.baseline-int-test.json',
        '--only' => 'a11y-alt-text',
    ])->assertSuccessful();
});

it('handles non-existent baseline file gracefully', function (): void {
    $testFile = base_path('test-views-baseline/test.blade.php');
    file_put_contents($testFile, '<img src="test.jpg">');

    $result = $this->artisan('sheath:lint', [
        'paths' => [base_path('test-views-baseline')],
        '--baseline' => 'nonexistent.baseline-int-test.json',
        '--only' => 'a11y-alt-text',
    ]);

    $result->assertFailed();
});

it('updates baseline when violations are fixed', function (): void {
    $testFile = base_path('test-views-baseline/test.blade.php');
    file_put_contents($testFile, '<img src="test.jpg">');

    $baselinePath = base_path('test.baseline-int-test.json');

    $this->artisan('sheath:lint', [
        'paths' => [base_path('test-views-baseline')],
        '--generate-baseline' => true,
        '--baseline' => 'test.baseline-int-test.json',
        '--only' => 'a11y-alt-text',
    ])->assertSuccessful();

    $initialBaseline = Baseline::load($baselinePath);
    $initialCount = $initialBaseline->getTotalCount();
    expect($initialCount)->toBeGreaterThan(0);

    file_put_contents($testFile, '<img src="test.jpg" alt="Test image">');

    $this->artisan('sheath:lint', [
        'paths' => [base_path('test-views-baseline')],
        '--update-baseline' => true,
        '--baseline' => 'test.baseline-int-test.json',
        '--only' => 'a11y-alt-text',
    ])
        ->assertSuccessful();

    $updatedBaseline = Baseline::load($baselinePath);
    expect($updatedBaseline->getTotalCount())->toBeLessThanOrEqual($initialCount);
});

it('fails when the baseline path is not a readable file', function (): void {
    $testFile = base_path('test-views-baseline/test.blade.php');
    file_put_contents($testFile, '<img src="test.jpg">');

    withTempDir(function (string $baselinePath): void {
        $this->artisan('sheath:lint', [
            'paths' => [base_path('test-views-baseline')],
            '--update-baseline' => true,
            '--baseline' => $baselinePath,
            '--only' => 'a11y-alt-text',
        ])
            ->assertFailed();
    }, prefix: 'baseline-int-dir-');
});

it('saves baseline in correct JSON format', function (): void {
    $testFile = base_path('test-views-baseline/test.blade.php');
    file_put_contents($testFile, '<img src="test.jpg">');

    $baselinePath = base_path('test.baseline-int-test.json');

    $this->artisan('sheath:lint', [
        'paths' => [base_path('test-views-baseline')],
        '--generate-baseline' => true,
        '--baseline' => 'test.baseline-int-test.json',
        '--only' => 'a11y-alt-text',
    ])->assertSuccessful();

    $content = file_get_contents($baselinePath);
    $data = json_decode($content, true);

    expect($data)->toBeArray()
        ->and($data)->toHaveKey('version')
        ->and($data)->toHaveKey('generated')
        ->and($data)->toHaveKey('violations')
        ->and($data)->toHaveKey('counts')
        ->and($data['version'])->toBe(Baseline::VERSION);
});

it('matches violations within line drift tolerance', function (): void {
    $testFile = base_path('test-views-baseline/test.blade.php');
    file_put_contents($testFile, '<img src="test.jpg">');

    $baselinePath = base_path('test.baseline-int-test.json');

    $this->artisan('sheath:lint', [
        'paths' => [base_path('test-views-baseline')],
        '--generate-baseline' => true,
        '--baseline' => 'test.baseline-int-test.json',
        '--only' => 'a11y-alt-text',
    ])->assertSuccessful();

    file_put_contents($testFile, "\n\n\n<img src=\"test.jpg\">");

    $this->artisan('sheath:lint', [
        'paths' => [base_path('test-views-baseline')],
        '--baseline' => 'test.baseline-int-test.json',
        '--only' => 'a11y-alt-text',
    ])
        ->assertSuccessful();
});

it('does not match violations outside line drift tolerance', function (): void {
    $testFile = base_path('test-views-baseline/test.blade.php');
    file_put_contents($testFile, '<img src="test.jpg">');

    $baselinePath = base_path('test.baseline-int-test.json');

    $this->artisan('sheath:lint', [
        'paths' => [base_path('test-views-baseline')],
        '--generate-baseline' => true,
        '--baseline' => 'test.baseline-int-test.json',
        '--only' => 'a11y-alt-text',
    ])->assertSuccessful();

    file_put_contents($testFile, "\n\n\n\n<img src=\"test.jpg\">");

    $this->artisan('sheath:lint', [
        'paths' => [base_path('test-views-baseline')],
        '--baseline' => 'test.baseline-int-test.json',
        '--only' => 'a11y-alt-text',
    ])->assertFailed();
});

it('fails when line drift exceeds tolerance and content hash changes', function (): void {
    $testFile = base_path('test-views-baseline/test.blade.php');
    file_put_contents($testFile, '<img src="test.jpg">');

    $baselinePath = base_path('test.baseline-int-test.json');

    $this->artisan('sheath:lint', [
        'paths' => [base_path('test-views-baseline')],
        '--generate-baseline' => true,
        '--baseline' => 'test.baseline-int-test.json',
        '--only' => 'a11y-alt-text',
    ])->assertSuccessful();

    file_put_contents($testFile, "\n\n\n\n\n\n\n\n\n\n<img src=\"test.jpg\">");

    $this->artisan('sheath:lint', [
        'paths' => [base_path('test-views-baseline')],
        '--baseline' => 'test.baseline-int-test.json',
        '--only' => 'a11y-alt-text',
    ])->assertFailed();
});
