<?php

declare(strict_types=1);

use Forte\Sheath\Configuration\Config;
use Forte\Sheath\Console\Handlers\ConfigHandler;
use Forte\Sheath\Exceptions\ConfigurationException;
use Forte\Sheath\Packages\PackageRequirementMode;
use Forte\Sheath\Rules\Accessibility\Content\ImgAltTextRule;
use Forte\Sheath\Rules\RuleRegistry;
use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

function makeConfigHandler(): ConfigHandler
{
    $registry = new RuleRegistry;
    $registry->register(ImgAltTextRule::class);

    $command = new class extends Command
    {
        protected $signature = 'test:config-handler';

        public function handle(): int
        {
            return self::SUCCESS;
        }
    };

    return new ConfigHandler($command, $registry);
}

function withTempConfigFile(string $contents, string $extension, callable $callback): void
{
    $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'sheath-config-shape-'.uniqid().'.'.$extension;
    file_put_contents($path, $contents);

    try {
        $callback($path);
    } finally {
        if (file_exists($path)) {
            unlink($path);
        }
    }
}

it('preserves non-rule config fields when applying rule overrides', function (): void {
    $registry = new RuleRegistry;
    $registry->register(ImgAltTextRule::class);

    $command = new class extends Command
    {
        protected $signature = 'test:config-handler';

        public function handle(): int
        {
            return self::SUCCESS;
        }
    };

    $handler = new ConfigHandler($command, $registry);

    $config = Config::fromArray([
        'paths' => ['resources/views'],
        'rules' => ['a11y-alt-text' => 'warning'],
        'ignore' => ['vendor/**'],
        'neverFix' => ['a11y-alt-text'],
        'baselineLineTolerance' => 9,
        'packageRequirementMode' => 'disable',
    ]);

    $updated = $handler->applyOverrides(
        config: $config,
        ruleOverrides: ['a11y-alt-text:error'],
        onlyRules: ['a11y-alt-text'],
    );

    expect($updated->getRuleSeverity('a11y-alt-text')?->value)->toBe('error')
        ->and($updated->getNeverFix())->toBe(['a11y-alt-text'])
        ->and($updated->getBaselineLineTolerance())->toBe(9)
        ->and($updated->getPackageRequirementMode())->toBe(PackageRequirementMode::DISABLE)
        ->and($updated->getPaths())->toBe(['resources/views'])
        ->and($updated->getIgnore())->toBe(['vendor/**']);
});

it('fails for unknown configured rule IDs', function (): void {
    $registry = new RuleRegistry;
    $registry->register(ImgAltTextRule::class);

    $command = new class extends Command
    {
        protected $signature = 'test:config-handler';

        public function handle(): int
        {
            return self::SUCCESS;
        }
    };

    $handler = new ConfigHandler($command, $registry);

    $handler->applyOverrides(
        config: Config::fromArray(['rules' => ['unknown-rule' => 'warning']]),
        ruleOverrides: null,
        onlyRules: null,
    );
})->throws(InvalidArgumentException::class);

it('fails for unknown --rule IDs', function (): void {
    $registry = new RuleRegistry;
    $registry->register(ImgAltTextRule::class);

    $command = new class extends Command
    {
        protected $signature = 'test:config-handler';

        public function handle(): int
        {
            return self::SUCCESS;
        }
    };

    $handler = new ConfigHandler($command, $registry);

    $handler->applyOverrides(
        config: Config::fromArray(['rules' => ['a11y-alt-text' => 'warning']]),
        ruleOverrides: ['unknown-rule:error'],
        onlyRules: null,
    );
})->throws(InvalidArgumentException::class);

describe('malformed --rule values', function (): void {
    it('fails when the severity half is missing', function (): void {
        makeConfigHandler()->applyOverrides(
            config: Config::fromArray(['rules' => ['a11y-alt-text' => 'warning']]),
            ruleOverrides: ['a11y-alt-text'],
            onlyRules: null,
        );
    })->throws(InvalidArgumentException::class);

    it('fails when the rule half is missing', function (): void {
        makeConfigHandler()->applyOverrides(
            config: Config::fromArray(['rules' => ['a11y-alt-text' => 'warning']]),
            ruleOverrides: [':error'],
            onlyRules: null,
        );
    })->throws(InvalidArgumentException::class);

    it('fails for a bare colon with nothing on either side', function (): void {
        makeConfigHandler()->applyOverrides(
            config: Config::fromArray(['rules' => ['a11y-alt-text' => 'warning']]),
            ruleOverrides: [':'],
            onlyRules: null,
        );
    })->throws(InvalidArgumentException::class);

    it('still applies a well-formed override', function (): void {
        $updated = makeConfigHandler()->applyOverrides(
            config: Config::fromArray(['rules' => ['a11y-alt-text' => 'warning']]),
            ruleOverrides: ['a11y-alt-text:error'],
            onlyRules: null,
        );

        expect($updated->getRuleSeverity('a11y-alt-text')?->value)->toBe('error');
    });
});

it('--only adds an out-of-config rule at its own default severity', function (): void {
    $updated = makeConfigHandler()->applyOverrides(
        config: Config::fromArray(['rules' => []]),
        ruleOverrides: null,
        onlyRules: ['a11y-alt-text'],
    );

    expect($updated->getRuleSeverity('a11y-alt-text'))
        ->toBe((new ImgAltTextRule)->getDefaultSeverity());
});

it('fails for unknown --only IDs', function (): void {
    $registry = new RuleRegistry;
    $registry->register(ImgAltTextRule::class);

    $command = new class extends Command
    {
        protected $signature = 'test:config-handler';

        public function handle(): int
        {
            return self::SUCCESS;
        }
    };

    $handler = new ConfigHandler($command, $registry);

    $handler->applyOverrides(
        config: Config::fromArray(['rules' => ['a11y-alt-text' => 'warning']]),
        ruleOverrides: null,
        onlyRules: ['unknown-rule'],
    );
})->throws(InvalidArgumentException::class);

it('prints rule status with resolved configuration', function (): void {
    $registry = new RuleRegistry;
    $registry->register(ImgAltTextRule::class);

    $command = new class extends Command
    {
        protected $signature = 'test:config-handler';

        public function handle(): int
        {
            return self::SUCCESS;
        }
    };
    $buffer = new BufferedOutput;
    $command->setOutput(new OutputStyle(new ArrayInput([]), $buffer));

    $handler = new ConfigHandler($command, $registry);
    $handler->print(Config::fromArray(['rules' => ['a11y-alt-text' => 'warning']]));

    $decoded = json_decode($buffer->fetch(), true);

    expect($decoded)->toHaveKey('ruleStatus')
        ->and($decoded['ruleStatus'])->toHaveKeys([
            'available',
            'skippedDueToPackages',
            'disabledDueToPackages',
        ])
        ->and($decoded['ruleStatus']['available'])->toContain('a11y-alt-text');
});

describe('--config file shape validation', function (): void {
    it('rejects a PHP file that returns a list', function (): void {
        $contents = <<<'PHP'
        <?php

        return [
            ['handle' => 'no_obsolete_tags', 'severity' => 'warning'],
            ['handle' => 'no_raw_echo', 'severity' => 'error'],
        ];
        PHP;

        withTempConfigFile($contents, 'php', function (string $path): void {
            $attempt = fn (): Config => makeConfigHandler()->loadFromFile($path);

            expect($attempt)->toThrow(ConfigurationException::class);
        });
    });

    it('rejects a PHP file with only unrecognized keys', function (): void {
        $contents = <<<'PHP'
        <?php

        return [
            'tags' => ['spacer'],
            'sets' => ['main'],
        ];
        PHP;

        withTempConfigFile($contents, 'php', function (string $path): void {
            expect(fn (): Config => makeConfigHandler()->loadFromFile($path))
                ->toThrow(ConfigurationException::class);
        });
    });

    it('rejects a typo of a recognized key', function (): void {
        $contents = <<<'PHP'
        <?php

        return [
            'rule' => ['a11y-alt-text' => 'off'],
        ];
        PHP;

        withTempConfigFile($contents, 'php', function (string $path): void {
            expect(fn (): Config => makeConfigHandler()->loadFromFile($path))
                ->toThrow(ConfigurationException::class);
        });
    });

    it('loads a file that sets only a preset', function (): void {
        withTempConfigFile("<?php\n\nreturn ['preset' => 'empty'];", 'php', function (string $path): void {
            expect(makeConfigHandler()->loadFromFile($path)->getPreset())->toBe(['empty']);
        });
    });

    it('loads a file that sets only rules', function (): void {
        withTempConfigFile("<?php\n\nreturn ['rules' => ['a11y-alt-text' => 'off']];", 'php', function (string $path): void {
            expect(makeConfigHandler()->loadFromFile($path)->getRuleSeverity('a11y-alt-text')?->value)->toBe('off');
        });
    });

    it('treats an empty array as an explicit request for defaults', function (): void {
        withTempConfigFile("<?php\n\nreturn [];", 'php', function (string $path): void {
            $config = makeConfigHandler()->loadFromFile($path);

            expect($config->getPreset())->toBe(['recommended'])
                ->and($config->getPaths())->toBe(['resources/views']);
        });
    });

    it('accepts every recognized top-level key', function (): void {
        $contents = <<<'PHP'
        <?php

        return [
            'preset' => 'recommended',
            'paths' => ['resources/views'],
            'ignore' => ['vendor/**'],
            'rules' => ['a11y-alt-text' => 'error'],
            'neverFix' => ['a11y-alt-text'],
            'baselineLineTolerance' => 3,
            'packageRequirementMode' => 'disable',
            'inlineSuppressions' => false,
        ];
        PHP;

        withTempConfigFile($contents, 'php', function (string $path): void {
            $config = makeConfigHandler()->loadFromFile($path);

            expect($config->getBaselineLineTolerance())->toBe(3)
                ->and($config->respectsInlineSuppressions())->toBeFalse();
        });
    });

    it('rejects a JSON file that contains a list', function (): void {
        withTempConfigFile('[{"handle": "no_obsolete_tags"}]', 'json', function (string $path): void {
            expect(fn (): Config => makeConfigHandler()->loadFromFile($path))
                ->toThrow(ConfigurationException::class);
        });
    });

    it('rejects unrecognized keys in a JSON file', function (): void {
        withTempConfigFile('{"rule": {"a11y-alt-text": "off"}}', 'json', function (string $path): void {
            expect(fn (): Config => makeConfigHandler()->loadFromFile($path))
                ->toThrow(ConfigurationException::class);
        });
    });

    it('loads a valid JSON file', function (): void {
        withTempConfigFile('{"rules": {"a11y-alt-text": "off"}}', 'json', function (string $path): void {
            expect(makeConfigHandler()->loadFromFile($path)->getRuleSeverity('a11y-alt-text')?->value)->toBe('off');
        });
    });
});
