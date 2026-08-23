<?php

declare(strict_types=1);

use Forte\Ast\Document\Document;
use Forte\Sheath\Attributes\RequiresPackage;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Rules\AbstractRule;
use Forte\Sheath\Rules\RuleCategory;
use Forte\Sheath\Rules\RuleContext;
use Forte\Sheath\Rules\RuleRegistry;
use Illuminate\Support\Facades\Artisan;

#[RequiresPackage('missing/sheath-test-package')]
class TestCommandPackageSkippedRule extends AbstractRule
{
    public function getId(): string
    {
        return 'test-command-package-skipped';
    }

    public function getDescription(): string
    {
        return 'Rule used to test package-skipped command visibility.';
    }

    public function getCategory(): RuleCategory
    {
        return RuleCategory::BLADE;
    }

    public function getDefaultSeverity(): Severity
    {
        return Severity::WARNING;
    }

    public function check(Document $document, RuleContext $context): void {}
}

it('fails when file has violations', function (): void {
    withSandbox(function (TestViewSandbox $sandbox): void {
        $sandbox->file('test.blade.php', '<img src="test.jpg">');

        $this->artisan('sheath:lint', [
            'paths' => [$sandbox->root],
            '--only' => 'a11y-alt-text',
        ])->assertFailed();
    });
});

it('succeeds when file has no violations', function (): void {
    withSandbox(function (TestViewSandbox $sandbox): void {
        $sandbox->file('test.blade.php', '<img src="test.jpg" alt="Test">');

        $this->artisan('sheath:lint', [
            'paths' => [$sandbox->root],
            '--only' => 'a11y-alt-text',
        ])->assertSuccessful();
    });
});

it('--output writes results to file', function (): void {
    withSandbox(function (TestViewSandbox $sandbox): void {
        $sandbox->file('test.blade.php', '<img src="test.jpg">');

        withTempFile(function (string $outputFile) use ($sandbox): void {
            $this->artisan('sheath:lint', [
                'paths' => [$sandbox->root],
                '--only' => 'a11y-alt-text',
                '--format' => 'json',
                '--output' => basename($outputFile),
            ]);

            expect(file_exists($outputFile))->toBeTrue();

            $report = json_decode((string) file_get_contents($outputFile), true);

            expect($report)->not->toBeNull()
                ->and($report)->toHaveKey('results');
        }, suffix: '.json', prefix: 'test-output-', inBasePath: true);
    });
});

it('--max-warnings fails when exceeds threshold', function (): void {
    withSandbox(function (TestViewSandbox $sandbox): void {
        $sandbox->file('test.blade.php', '<div style="color:red">test</div>');

        $this->artisan('sheath:lint', [
            'paths' => [$sandbox->root],
            '--only' => 'best-practices-no-inline-styles',
            '--max-warnings' => 0,
        ])->assertFailed();
    });
});

it('--max-warnings succeeds when within threshold', function (): void {
    withSandbox(function (TestViewSandbox $sandbox): void {
        $sandbox->file('test.blade.php', '<div style="color:red">test</div>');

        $this->artisan('sheath:lint', [
            'paths' => [$sandbox->root],
            '--only' => 'best-practices-no-inline-styles',
            '--max-warnings' => 10,
        ])->assertSuccessful();
    });
});

it('agent result follows the max-warnings exit threshold', function (): void {
    withSandbox(function (TestViewSandbox $sandbox): void {
        $sandbox->file('test.blade.php', '<div style="color:red">test</div>');

        $this->artisan('sheath:lint', [
            'paths' => [$sandbox->root],
            '--only' => 'best-practices-no-inline-styles',
            '--max-warnings' => 0,
            '--format' => 'agent',
        ])
            ->expectsOutputToContain('"result":"fail"')
            ->assertFailed();
    });
});

it('--print-config outputs configuration', function (): void {
    $this->artisan('sheath:lint', [
        '--print-config' => true,
    ])
        ->assertSuccessful()
        ->expectsOutputToContain('"rules"');
});

it('--print-config still validates the selected reporter', function (): void {
    $this->artisan('sheath:lint', [
        '--print-config' => true,
        '--format' => 'not-a-reporter',
    ])
        ->assertFailed();
});

it('--print-config includes CLI rule overrides', function (): void {
    $this->artisan('sheath:lint', [
        '--print-config' => true,
        '--rule' => 'a11y-alt-text:error',
    ])
        ->assertSuccessful()
        ->expectsOutputToContain('"a11y-alt-text": "error"');
});

it('--config loads from PHP file', function (): void {
    withSandbox(function (TestViewSandbox $sandbox): void {
        withTempFile(function (string $configFile) use ($sandbox): void {
            $config = <<<'PHP'
            <?php
            return [
                'rules' => [
                    'a11y-alt-text' => 'off',
                ],
            ];
            PHP;

            file_put_contents($configFile, $config);
            $sandbox->file('test.blade.php', '<img src="test.jpg">');

            $this->artisan('sheath:lint', [
                'paths' => [$sandbox->root],
                '--config' => basename($configFile),
            ])->assertSuccessful();
        }, suffix: '.php', prefix: 'test-config-', inBasePath: true);
    });
});

it('--config loads from an absolute PHP file path', function (): void {
    withSandbox(function (TestViewSandbox $sandbox): void {
        withTempFile(function (string $configFile) use ($sandbox): void {
            $config = <<<'PHP'
            <?php
            return [
                'rules' => [
                    'a11y-alt-text' => 'off',
                ],
            ];
            PHP;

            file_put_contents($configFile, $config);
            $sandbox->file('test.blade.php', '<img src="test.jpg">');

            $this->artisan('sheath:lint', [
                'paths' => [$sandbox->root],
                '--config' => $configFile,
            ])->assertSuccessful();
        }, suffix: '.php', prefix: 'test-config-');
    });
});

it('--config fails the run for a missing file', function (): void {
    withSandbox(function (TestViewSandbox $sandbox): void {
        $sandbox->file('test.blade.php', '<div>test</div>');

        $this->artisan('sheath:lint', [
            'paths' => [$sandbox->root],
            '--config' => 'nonexistent-config.php',
            '--only' => 'a11y-alt-text',
        ])->assertFailed();
    });
});

it('--config fails the run for an unsupported format', function (): void {
    withSandbox(function (TestViewSandbox $sandbox): void {
        $sandbox->file('test.blade.php', '<div>test</div>');
        $configPath = $sandbox->file('sheath.yaml', 'preset: recommended');

        $this->artisan('sheath:lint', [
            'paths' => [$sandbox->root],
            '--config' => $configPath,
            '--only' => 'a11y-alt-text',
        ])->assertFailed();
    });
});

it('--rule overrides severity', function (): void {
    withSandbox(function (TestViewSandbox $sandbox): void {
        $sandbox->file('test.blade.php', '<img src="test.jpg">');

        $this->artisan('sheath:lint', [
            'paths' => [$sandbox->root],
            '--only' => 'a11y-alt-text',
            '--rule' => 'a11y-alt-text:off',
        ])->assertSuccessful();
    });
});

it('--rule fails for a value missing the severity half', function (): void {
    withSandbox(function (TestViewSandbox $sandbox): void {
        $sandbox->file('test.blade.php', '<div>test</div>');

        $this->artisan('sheath:lint', [
            'paths' => [$sandbox->root],
            '--rule' => 'a11y-alt-text',
        ])
            ->assertFailed();
    });
});

it('--rule fails for a value missing the rule half', function (): void {
    withSandbox(function (TestViewSandbox $sandbox): void {
        $sandbox->file('test.blade.php', '<div>test</div>');

        $this->artisan('sheath:lint', [
            'paths' => [$sandbox->root],
            '--rule' => ':error',
        ])
            ->assertFailed();
    });
});

it('--max-warnings rejects a non-integer value', function (): void {
    withSandbox(function (TestViewSandbox $sandbox): void {
        $sandbox->file('test.blade.php', '<div>test</div>');

        $this->artisan('sheath:lint', [
            'paths' => [$sandbox->root],
            '--max-warnings' => 'abc',
        ])
            ->assertFailed();
    });
});

it('reports parse errors and exits non-zero', function (): void {
    withSandbox(function (TestViewSandbox $sandbox): void {
        $sandbox->file('broken.blade.php', '<p>{{ $title</p>');

        $this->artisan('sheath:lint', [
            'paths' => [$sandbox->root],
            '--only' => 'a11y-alt-text',
        ])
            ->assertFailed()
            ->expectsOutputToContain('parse-error');
    });
});

it('reports parser depth limits and continues linting later files', function (): void {
    withSandbox(function (TestViewSandbox $sandbox): void {
        $sandbox->file('a-deep.blade.php', str_repeat('<div>', 2048).str_repeat('</div>', 2048));
        $sandbox->file('z-later.blade.php', '<img src="later.jpg">');

        $status = Artisan::call('sheath:lint', [
            'paths' => [$sandbox->root],
            '--only' => 'a11y-alt-text',
            '--format' => 'json',
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $ruleIds = [];
        foreach ($payload['results'] ?? [] as $result) {
            foreach ($result['violations'] ?? [] as $violation) {
                $ruleIds[] = $violation['ruleId'] ?? null;
            }
        }

        expect($status)->toBe(1)
            ->and($payload['results'] ?? [])->toHaveCount(2)
            ->and($ruleIds)->toBe(['parse-error', 'a11y-alt-text']);
    });
});

it('--fix and --generate-baseline cannot be combined', function (): void {
    withSandbox(function (TestViewSandbox $sandbox): void {
        $sandbox->file('test.blade.php', '<button>Go</button>');

        $this->artisan('sheath:lint', [
            'paths' => [$sandbox->root],
            '--only' => 'best-practices-button-type',
            '--fix' => true,
            '--generate-baseline' => true,
        ])
            ->assertFailed();
    });
});

it('--generate-baseline and --update-baseline are mutually exclusive', function (): void {
    withSandbox(function (TestViewSandbox $sandbox): void {
        $sandbox->file('test.blade.php', '<div>test</div>');

        $this->artisan('sheath:lint', [
            'paths' => [$sandbox->root],
            '--generate-baseline' => true,
            '--update-baseline' => true,
        ])
            ->assertFailed();
    });
});

it('--rule shows an error for invalid severity values', function (): void {
    withSandbox(function (TestViewSandbox $sandbox): void {
        $sandbox->file('test.blade.php', '<img src="test.jpg">');

        $this->artisan('sheath:lint', [
            'paths' => [$sandbox->root],
            '--rule' => 'a11y-alt-text:not-a-severity',
        ])
            ->assertFailed();
    });
});

it('--only runs only specified rules', function (): void {
    withSandbox(function (TestViewSandbox $sandbox): void {
        $sandbox->file('test.blade.php', '<img src="test.jpg">');

        $this->artisan('sheath:lint', [
            'paths' => [$sandbox->root],
            '--only' => 'best-practices-no-inline-styles',
        ])->assertSuccessful();
    });
});

it('--only shows an error for unknown rule IDs', function (): void {
    withSandbox(function (TestViewSandbox $sandbox): void {
        $sandbox->file('test.blade.php', '<img src="test.jpg">');

        $this->artisan('sheath:lint', [
            'paths' => [$sandbox->root],
            '--only' => 'unknown-rule',
        ])
            ->assertFailed();
    });
});

it('--rule shows an error for unknown rule IDs', function (): void {
    withSandbox(function (TestViewSandbox $sandbox): void {
        $sandbox->file('test.blade.php', '<img src="test.jpg">');

        $this->artisan('sheath:lint', [
            'paths' => [$sandbox->root],
            '--rule' => 'unknown-rule:error',
        ])
            ->assertFailed();
    });
});

it('--config shows an error for unknown configured rules', function (): void {
    withSandbox(function (TestViewSandbox $sandbox): void {
        withTempFile(function (string $configFile) use ($sandbox): void {
            $config = <<<'PHP'
            <?php
            return [
                'rules' => [
                    'unknown-rule' => 'warning',
                ],
            ];
            PHP;

            file_put_contents($configFile, $config);
            $sandbox->file('test.blade.php', '<img src="test.jpg">');

            $this->artisan('sheath:lint', [
                'paths' => [$sandbox->root],
                '--config' => basename($configFile),
            ])
                ->assertFailed();
        }, suffix: '.php', prefix: 'test-config-', inBasePath: true);
    });
});

it('--cache creates cache file', function (): void {
    withSandbox(function (TestViewSandbox $sandbox): void {
        $sandbox->file('test.blade.php', '<div>test</div>');

        withTempFile(function (string $cacheFile) use ($sandbox): void {
            $this->artisan('sheath:lint', [
                'paths' => [$sandbox->root],
                '--only' => 'a11y-alt-text',
                '--cache' => true,
                '--cache-location' => basename($cacheFile),
            ]);

            expect(file_exists($cacheFile))->toBeTrue();
        }, prefix: '.test-cache-', inBasePath: true);
    });
});

it('--cache creates cache file at an absolute location', function (): void {
    withSandbox(function (TestViewSandbox $sandbox): void {
        $sandbox->file('test.blade.php', '<div>test</div>');

        withTempFile(function (string $cacheFile) use ($sandbox): void {
            $this->artisan('sheath:lint', [
                'paths' => [$sandbox->root],
                '--only' => 'a11y-alt-text',
                '--cache' => true,
                '--cache-location' => $cacheFile,
            ])->assertSuccessful();

            expect(file_exists($cacheFile))->toBeTrue();
        }, prefix: '.test-cache-');
    });
});

it('--cache invalidates when --only changes', function (): void {
    withSandbox(function (TestViewSandbox $sandbox): void {
        $sandbox->file('test.blade.php', '<img src="test.jpg">');

        withTempFile(function (string $cacheFile) use ($sandbox): void {
            $this->artisan('sheath:lint', [
                'paths' => [$sandbox->root],
                '--only' => 'best-practices-no-inline-styles',
                '--cache' => true,
                '--cache-location' => $cacheFile,
            ])->assertSuccessful();

            $this->artisan('sheath:lint', [
                'paths' => [$sandbox->root],
                '--only' => 'a11y-alt-text',
                '--cache' => true,
                '--cache-location' => $cacheFile,
            ])->assertFailed();
        }, prefix: '.test-cache-');
    });
});

it('--cache invalidates when --rule severity changes', function (): void {
    withSandbox(function (TestViewSandbox $sandbox): void {
        $sandbox->file('test.blade.php', '<img src="test.jpg">');

        withTempFile(function (string $cacheFile) use ($sandbox): void {
            $this->artisan('sheath:lint', [
                'paths' => [$sandbox->root],
                '--only' => 'a11y-alt-text',
                '--rule' => 'a11y-alt-text:warning',
                '--cache' => true,
                '--cache-location' => $cacheFile,
            ])->assertSuccessful();

            $this->artisan('sheath:lint', [
                'paths' => [$sandbox->root],
                '--only' => 'a11y-alt-text',
                '--rule' => 'a11y-alt-text:error',
                '--cache' => true,
                '--cache-location' => $cacheFile,
            ])->assertFailed();
        }, prefix: '.test-cache-');
    });
});

it('accepts a package-skipped rule selected by --only', function (): void {
    /** @var RuleRegistry $registry */
    $registry = app(RuleRegistry::class);
    $registry->register(TestCommandPackageSkippedRule::class);

    withSandbox(function (TestViewSandbox $sandbox): void {
        $sandbox->file('test.blade.php', '<div>test</div>');

        $this->artisan('sheath:lint', [
            'paths' => [$sandbox->root],
            '--only' => 'test-command-package-skipped',
        ])
            ->assertSuccessful();
    });
});

it('--print-config includes rule status', function (): void {
    $this->artisan('sheath:lint', [
        '--print-config' => true,
    ])
        ->assertSuccessful()
        ->expectsOutputToContain('"ruleStatus"');
});

it('honours inline suppression comments by default', function (): void {
    withSandbox(function (TestViewSandbox $sandbox): void {
        $sandbox->file(
            'test.blade.php',
            "{{-- sheath-disable-next-line a11y-alt-text --}}\n<img src=\"test.jpg\">"
        );

        $this->artisan('sheath:lint', [
            'paths' => [$sandbox->root],
            '--only' => 'a11y-alt-text',
        ])->assertSuccessful();
    });
});

it('--no-inline-config overrules suppression comments', function (): void {
    withSandbox(function (TestViewSandbox $sandbox): void {
        $sandbox->file(
            'test.blade.php',
            "{{-- sheath-disable-next-line a11y-alt-text --}}\n<img src=\"test.jpg\">"
        );

        $this->artisan('sheath:lint', [
            'paths' => [$sandbox->root],
            '--only' => 'a11y-alt-text',
            '--no-inline-config' => true,
        ])->assertFailed();
    });
});

it('--fix does not rewrite a suppressed line', function (): void {
    withSandbox(function (TestViewSandbox $sandbox): void {
        $original = "{{-- sheath-disable-next-line best-practices-button-type --}}\n<button>Go</button>";
        $file = $sandbox->file('test.blade.php', $original);

        $this->artisan('sheath:lint', [
            'paths' => [$sandbox->root],
            '--only' => 'best-practices-button-type',
            '--fix' => true,
        ]);

        expect(file_get_contents($file))->toBe($original);
    });
});

it('--fix applies fixes', function (): void {
    withSandbox(function (TestViewSandbox $sandbox): void {
        $file = $sandbox->file(
            'test.blade.php',
            '<script type="text/javascript">console.log("test");</script>'
        );

        $this->artisan('sheath:lint', [
            'paths' => [$sandbox->root],
            '--only' => 'best-practices-no-script-style-type',
            '--fix' => true,
        ]);

        expect(file_get_contents($file))->not->toContain('type=');
    });
});

it('--fix re-lints modified files before determining exit status', function (): void {
    withSandbox(function (TestViewSandbox $sandbox): void {
        $file = $sandbox->file('test.blade.php', '<form action="/save"><button>Submit</button></form>');

        $this->artisan('sheath:lint', [
            'paths' => [$sandbox->root],
            '--only' => 'best-practices-button-type',
            '--rule' => 'best-practices-button-type:error',
            '--fix' => true,
        ])->assertSuccessful();

        expect(file_get_contents($file))->toContain('type="submit"');
    });
});

it('--fix preserves unknown role fallbacks beside a concrete role', function (): void {
    withSandbox(function (TestViewSandbox $sandbox): void {
        $file = $sandbox->file('test.blade.php', '<div role="navigation widget invalid">Content</div>');

        $this->artisan('sheath:lint', [
            'paths' => [$sandbox->root],
            '--only' => 'a11y-no-abstract-roles,a11y-no-invalid-role',
            '--fix' => true,
        ])->assertSuccessful();

        expect(file_get_contents($file))->toBe('<div role="navigation invalid">Content</div>');
    });
});

it('--dangerous composes lazy-image insertion with self-closing cleanup without corruption', function (): void {
    withSandbox(function (TestViewSandbox $sandbox): void {
        $file = $sandbox->file('test.blade.php', '<img src="hero.jpg" /><img src="below.jpg" />');

        $this->artisan('sheath:lint', [
            'paths' => [$sandbox->root],
            '--only' => 'perf-lazy-load-images,best-practices-self-closing-void-elements',
            '--fix' => true,
            '--dangerous' => true,
        ])->assertSuccessful();

        expect(file_get_contents($file))->toBe('<img src="hero.jpg"><img src="below.jpg" loading="lazy">');
    });
});

it('--fix skips dangerous fixes by default', function (): void {
    withSandbox(function (TestViewSandbox $sandbox): void {
        $original = '<div style="color:red">test</div>';
        $file = $sandbox->file('test.blade.php', $original);

        $this->artisan('sheath:lint', [
            'paths' => [$sandbox->root],
            '--only' => 'best-practices-no-inline-styles',
            '--fix' => true,
        ])->assertSuccessful();

        expect(file_get_contents($file))->toBe($original);
    });
});

it('--dangerous applies dangerous fixes', function (): void {
    withSandbox(function (TestViewSandbox $sandbox): void {
        $file = $sandbox->file('test.blade.php', '<div style="color:red">test</div>');

        $this->artisan('sheath:lint', [
            'paths' => [$sandbox->root],
            '--only' => 'best-practices-no-inline-styles',
            '--fix' => true,
            '--dangerous' => true,
        ])->assertSuccessful();

        expect(file_get_contents($file))->not->toContain('style=');
    });
});

it('--fix-dangerous alias applies dangerous fixes', function (): void {
    withSandbox(function (TestViewSandbox $sandbox): void {
        $file = $sandbox->file('test.blade.php', '<div style="color:red">test</div>');

        $this->artisan('sheath:lint', [
            'paths' => [$sandbox->root],
            '--only' => 'best-practices-no-inline-styles',
            '--fix' => true,
            '--fix-dangerous' => true,
        ])->assertSuccessful();

        expect(file_get_contents($file))->not->toContain('style=');
    });
});

it('--dry-run does not modify files', function (): void {
    withSandbox(function (TestViewSandbox $sandbox): void {
        $original = '<script type="text/javascript">console.log("test");</script>';
        $file = $sandbox->file('test.blade.php', $original);

        $this->artisan('sheath:lint', [
            'paths' => [$sandbox->root],
            '--only' => 'best-practices-no-script-style-type',
            '--dry-run' => true,
        ]);

        expect(file_get_contents($file))->toBe($original);
    });
});

it('--output writes results to an absolute file path', function (): void {
    withSandbox(function (TestViewSandbox $sandbox): void {
        $sandbox->file('test.blade.php', '<img src="test.jpg" alt="Test">');

        withTempFile(function (string $outputFile) use ($sandbox): void {
            $this->artisan('sheath:lint', [
                'paths' => [$sandbox->root],
                '--only' => 'a11y-alt-text',
                '--format' => 'json',
                '--output' => $outputFile,
            ])->assertSuccessful();

            expect(file_exists($outputFile))->toBeTrue();

            $report = json_decode((string) file_get_contents($outputFile), true);

            expect($report)->not->toBeNull()
                ->and($report)->toHaveKey('results');
        }, suffix: '.json', prefix: 'test-output-');
    });
});

it('--output atomically replaces an existing report file', function (): void {
    withSandbox(function (TestViewSandbox $sandbox): void {
        $sandbox->file('test.blade.php', '<img src="test.jpg">');

        withTempFile(function (string $outputFile) use ($sandbox): void {
            file_put_contents($outputFile, '{"old":true}');
            $inodeBefore = fileinode($outputFile);

            $this->artisan('sheath:lint', [
                'paths' => [$sandbox->root],
                '--format' => 'json',
                '--output' => $outputFile,
                '--only' => 'a11y-alt-text',
            ])->assertFailed();

            clearstatcache(true, $outputFile);
            if (PHP_OS_FAMILY !== 'Windows') {
                expect(fileinode($outputFile))->not->toBe($inodeBefore);
            }

            expect(fn () => json_decode((string) file_get_contents($outputFile), true, flags: JSON_THROW_ON_ERROR))
                ->not->toThrow(Throwable::class);
        }, suffix: '.json');
    });
});

it('--format shows an error for unknown reporters', function (): void {
    withSandbox(function (TestViewSandbox $sandbox): void {
        $sandbox->file('test.blade.php', '<img src="test.jpg">');

        $this->artisan('sheath:lint', [
            'paths' => [$sandbox->root],
            '--only' => 'a11y-alt-text',
            '--format' => 'not-a-reporter',
        ])
            ->assertFailed();
    });
});

it('--output fails when the target cannot be written', function (): void {
    withSandbox(function (TestViewSandbox $sandbox): void {
        $sandbox->file('test.blade.php', '<img src="test.jpg">');

        withTempDir(function (string $outputDirectory) use ($sandbox): void {
            $this->artisan('sheath:lint', [
                'paths' => [$sandbox->root],
                '--only' => 'a11y-alt-text',
                '--format' => 'json',
                '--output' => $outputDirectory,
            ])
                ->assertFailed();
        }, prefix: 'test-output-dir-');
    });
});

it('--quiet does not change max-warnings failure behavior', function (): void {
    withSandbox(function (TestViewSandbox $sandbox): void {
        $sandbox->file('test.blade.php', '<div style="color:red">test</div>');

        $this->artisan('sheath:lint', [
            'paths' => [$sandbox->root],
            '--only' => 'best-practices-no-inline-styles',
            '--max-warnings' => 0,
            '--quiet' => true,
        ])->assertFailed();
    });
});

it('--processes requires a positive integer', function (): void {
    withSandbox(function (TestViewSandbox $sandbox): void {
        $sandbox->file('test.blade.php', '<img src="test.jpg" alt="Valid">');

        $this->artisan('sheath:lint', [
            'paths' => [$sandbox->root],
            '--parallel' => true,
            '--processes' => '0',
        ])
            ->assertFailed();
    });
});

it('--processes rejects non-numeric values', function (): void {
    withSandbox(function (TestViewSandbox $sandbox): void {
        $sandbox->file('test.blade.php', '<img src="test.jpg" alt="Valid">');

        $this->artisan('sheath:lint', [
            'paths' => [$sandbox->root],
            '--parallel' => true,
            '--processes' => 'abc',
        ])
            ->assertFailed();
    });
});

it('fails when no files found', function (): void {
    $this->artisan('sheath:lint', [
        'paths' => ['nonexistent-directory-'.uniqid()],
    ])
        ->assertFailed();
});

it('lints files in parent directory', function (): void {
    withSandbox(function (TestViewSandbox $sandbox): void {
        $sandbox->file('index.blade.php', '<img src="test.jpg">');

        $this->artisan('sheath:lint', [
            'paths' => [$sandbox->root],
            '--only' => 'a11y-alt-text',
        ])->assertFailed();
    }, TestViewSandbox::makeWithEmails());
});

it('ignores files matching ignore patterns via --ignore-pattern', function (): void {
    withSandbox(function (TestViewSandbox $sandbox): void {
        $sandbox->file('emails/welcome.blade.php', '<img src="test.jpg">');

        $this->artisan('sheath:lint', [
            'paths' => [$sandbox->root],
            '--only' => 'a11y-alt-text',
            '--ignore-pattern' => ['emails/**'],
        ])
            ->assertFailed();
    }, TestViewSandbox::makeWithEmails());
});

it('lints non-ignored files while ignoring matched patterns', function (): void {
    withSandbox(function (TestViewSandbox $sandbox): void {
        $sandbox->file('index.blade.php', '<img src="test.jpg" alt="Test image">');
        $sandbox->file('emails/welcome.blade.php', '<img src="test.jpg">');

        $this->artisan('sheath:lint', [
            'paths' => [$sandbox->root],
            '--only' => 'a11y-alt-text',
            '--ignore-pattern' => ['emails/**'],
        ])->assertSuccessful();
    }, TestViewSandbox::makeWithEmails());
});

it('fails when non-ignored file has violations even if ignored file also has them', function (): void {
    withSandbox(function (TestViewSandbox $sandbox): void {
        $sandbox->file('index.blade.php', '<img src="parent.jpg">');
        $sandbox->file('emails/welcome.blade.php', '<img src="email.jpg">');

        $this->artisan('sheath:lint', [
            'paths' => [$sandbox->root],
            '--only' => 'a11y-alt-text',
            '--ignore-pattern' => ['emails/**'],
        ])->assertFailed();
    }, TestViewSandbox::makeWithEmails());
});

it('respects ignore patterns from config file', function (): void {
    withSandbox(function (TestViewSandbox $sandbox): void {
        $sandbox->file('index.blade.php', '<img src="test.jpg" alt="Test image">');
        $sandbox->file('emails/welcome.blade.php', '<img src="test.jpg">');

        withTempFile(function (string $configFile) use ($sandbox): void {
            $configContent = <<<'PHP'
            <?php
            return [
                'ignore' => [
                    'emails/**',
                ],
            ];
            PHP;

            file_put_contents($configFile, $configContent);

            $this->artisan('sheath:lint', [
                'paths' => [$sandbox->root],
                '--only' => 'a11y-alt-text',
                '--config' => basename($configFile),
            ])->assertSuccessful();
        }, suffix: '.php', prefix: 'test-ignore-config-', inBasePath: true);
    }, TestViewSandbox::makeWithEmails());
});

it('--no-ignore disables all ignore patterns', function (): void {
    withSandbox(function (TestViewSandbox $sandbox): void {
        $sandbox->file('emails/welcome.blade.php', '<img src="test.jpg">');

        $this->artisan('sheath:lint', [
            'paths' => [$sandbox->root],
            '--only' => 'a11y-alt-text',
            '--ignore-pattern' => ['emails/**'],
        ])
            ->assertFailed();

        $this->artisan('sheath:lint', [
            'paths' => [$sandbox->root],
            '--only' => 'a11y-alt-text',
            '--ignore-pattern' => ['emails/**'],
            '--no-ignore' => true,
        ])->assertFailed();
    }, TestViewSandbox::makeWithEmails());
});

/**
 * @param  array<string, mixed>  $options
 * @return array<string>
 */
function reportedRuleIds(TestViewSandbox $sandbox, array $options): array
{
    return withTempFile(function (string $outputFile) use ($sandbox, $options): array {
        Artisan::call('sheath:lint', array_merge([
            'paths' => [$sandbox->root],
            '--format' => 'json',
            '--output' => basename($outputFile),
        ], $options));

        /** @var array{results?: array<array{violations?: array<array{ruleId?: string}>}>} $report */
        $report = json_decode((string) file_get_contents($outputFile), true);

        $ids = [];
        foreach ($report['results'] ?? [] as $file) {
            foreach ($file['violations'] ?? [] as $violation) {
                $ids[] = $violation['ruleId'] ?? '';
            }
        }

        return array_values(array_unique($ids));
    }, suffix: '.json', prefix: 'preset-run-', inBasePath: true);
}

it('--preset runs the named preset in place of the configured one', function (): void {
    withSandbox(function (TestViewSandbox $sandbox): void {
        $sandbox->file('test.blade.php', "@section('c')\n<img src=\"a.jpg\">\n@stop");

        expect(reportedRuleIds($sandbox, ['--preset' => ['migration']]))
            ->toContain('blade-prefer-endsection')
            ->not->toContain('a11y-alt-text');

        expect(reportedRuleIds($sandbox, ['--preset' => ['recommended']]))
            ->toContain('a11y-alt-text')
            ->not->toContain('blade-prefer-endsection');
    });
});

it('--preset accepts several presets, comma-separated or repeated', function (): void {
    withSandbox(function (TestViewSandbox $sandbox): void {
        $sandbox->file('test.blade.php', "@section('c')\n<img src=\"a.jpg\">\n@stop");

        foreach ([['recommended,migration'], ['recommended', 'migration']] as $option) {
            expect(reportedRuleIds($sandbox, ['--preset' => $option]))
                ->toContain('blade-prefer-endsection')
                ->toContain('a11y-alt-text');
        }
    });
});

it('--preset rejects a name that is not a preset', function (): void {
    withSandbox(function (TestViewSandbox $sandbox): void {
        $sandbox->file('test.blade.php', '<img src="a.jpg" alt="a">');

        $this->artisan('sheath:lint', [
            'paths' => [$sandbox->root],
            '--preset' => ['nope'],
        ])
            ->assertFailed();
    });
});

it('uses default config ignore patterns from Laravel config', function (): void {
    withSandbox(function (TestViewSandbox $sandbox): void {
        $sandbox->file('index.blade.php', '<img src="test.jpg" alt="Valid">');
        $sandbox->file('emails/welcome.blade.php', '<img src="test.jpg">');

        /** @var array<string, mixed> $originalConfig */
        $originalConfig = config('sheath');

        config()->set('sheath', array_merge($originalConfig, [
            'paths' => [$sandbox->root],
            'ignore' => ['emails/**'],
            'rules' => [
                'a11y-alt-text' => 'error',
            ],
        ]));

        try {
            $this->artisan('sheath:lint', [
                '--only' => 'a11y-alt-text',
            ])->assertSuccessful();
        } finally {
            config()->set('sheath', $originalConfig);
        }
    }, TestViewSandbox::makeWithEmails());
});

it('applies wildcard-prefixed ignore patterns from config before fixing', function (): void {
    withSandbox(function (TestViewSandbox $sandbox): void {
        $sandbox->file('index.blade.php', '<script>go()</script>');
        $ignored = '<script type="text/javascript">ignored()</script>';
        $ignoredPath = $sandbox->file('emails/welcome.blade.php', $ignored);

        /** @var array<string, mixed> $originalConfig */
        $originalConfig = config('sheath');

        config()->set('sheath', array_merge($originalConfig, [
            'paths' => [$sandbox->root],
            'ignore' => ['test-views-nested-*/emails/**'],
            'rules' => [
                'best-practices-no-script-style-type' => 'warning',
            ],
        ]));

        try {
            $this->artisan('sheath:lint', [
                '--only' => 'best-practices-no-script-style-type',
                '--fix' => true,
            ])->assertSuccessful();

            expect(file_get_contents($ignoredPath))->toBe($ignored);
        } finally {
            config()->set('sheath', $originalConfig);
        }
    }, TestViewSandbox::makeWithEmails());
});

it('applies safe fixes while leaving dangerous fixes untouched', function (): void {
    withSandbox(function (TestViewSandbox $sandbox): void {
        $path = $sandbox->file(
            'test.blade.php',
            '<body><button accesskey="s" type="button">One</button><button>Two</button></body>'
        );

        $this->artisan('sheath:lint', [
            'paths' => [$sandbox->root],
            '--only' => 'a11y-no-accesskey,best-practices-button-type',
            '--fix' => true,
        ]);

        expect(file_get_contents($path))
            ->toContain('<button type="button">Two</button>')
            ->toContain('accesskey="s"');
    });
});

it('--print-config serializes empty package-status maps as objects', function (): void {
    Artisan::call('sheath:lint', ['--print-config' => true]);
    $payload = json_decode(Artisan::output(), false, flags: JSON_THROW_ON_ERROR);

    expect($payload->ruleStatus->skippedDueToPackages)->toBeObject()
        ->and($payload->ruleStatus->disabledDueToPackages)->toBeObject();
});

it('resolves relative path arguments against the project root, not the cwd', function (): void {
    withSandbox(function (TestViewSandbox $sandbox): void {
        $sandbox->file('test.blade.php', '<img src="test.jpg">');

        $this->artisan('sheath:lint', [
            'paths' => [basename($sandbox->root)],
            '--only' => 'a11y-alt-text',
        ])->assertFailed();
    });
});
