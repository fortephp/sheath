<?php

declare(strict_types=1);

use Forte\Sheath\Configuration\Config;
use Forte\Sheath\Linter;
use Forte\Sheath\Reporters\AgentReporter;
use Forte\Sheath\Reporters\ReporterRegistry;
use Forte\Sheath\Rules\Accessibility\Content\ImgAltTextRule;
use Forte\Sheath\Rules\Blade\Helpers\PreferLangHelperRule;
use Forte\Sheath\Rules\RuleRegistry;
use Forte\Sheath\Rules\Security\NoTargetBlankRule;

function formattedOutput(string $format): string
{
    $registry = new RuleRegistry;
    $registry->register($rule = new ImgAltTextRule);

    $config = Config::make();
    $config->setRule($rule->getId(), ['severity' => 'error', 'options' => []]);

    $result = (new Linter($registry))->lint("<div>\n  <img src=\"a.png\">\n</div>", 'resources/views/dashboard.blade.php', $config);

    return (new ReporterRegistry)->get($format)->formatMany([$result]);
}

describe('the shape each formatter emits', function (): void {
    it('json: these keys and no others', function (): void {
        $decoded = json_decode(formattedOutput('json'), true);

        expect($decoded)->toBeArray()
            ->and(array_keys($decoded))->toEqualCanonicalizing(['schemaVersion', 'results', 'summary'])
            ->and($decoded['schemaVersion'])->toBe(1);

        expect(array_keys($decoded['summary']))->toEqualCanonicalizing(['files', 'errors', 'warnings', 'infos', 'fixable']);

        $result = $decoded['results'][0];
        expect(array_keys($result))->toEqualCanonicalizing([
            'filePath', 'violations', 'hasParseErrors', 'errorCount', 'warningCount', 'infoCount', 'fixableCount',
        ]);

        $violation = $result['violations'][0];
        expect(array_keys($violation))->toEqualCanonicalizing([
            'ruleId', 'message', 'severity', 'filePath',
            'offset', 'line', 'column', 'endOffset', 'endLine', 'endColumn',
            'fix', 'fixAvailable', 'dangerousFix',
        ]);
    });

    it('compact: "file: line N, col N, SEVERITY - message (ruleId)"', function (): void {
        $line = trim(strtok(formattedOutput('compact'), "\n") ?: '');

        expect($line)->toMatch('/^.+\.blade\.php: line \d+, col \d+, [A-Z]+ - .+ \([a-z0-9-]+\)$/');
    });

    it('unix: "file:line:column: message [ruleId]"', function (): void {
        $line = trim(strtok(formattedOutput('unix'), "\n") ?: '');

        expect($line)->toMatch('/^.+\.blade\.php:\d+:\d+: .+ \[[a-z0-9-]+\]$/');
    });

    it('checkstyle: the element and attribute names SonarQube reads', function (): void {
        $xml = formattedOutput('checkstyle');

        expect($xml)->toStartWith('<?xml version="1.0" encoding="UTF-8"?>');

        $parsed = simplexml_load_string($xml);

        expect($parsed)->not->toBeFalse()
            ->and($parsed->getName())->toBe('checkstyle')
            ->and((string) $parsed['version'])->not->toBe('');

        $file = $parsed->file[0];
        expect((string) $file['name'])->toBe('resources/views/dashboard.blade.php');

        $error = $file->error[0];
        foreach (['line', 'column', 'severity', 'message', 'source'] as $attribute) {
            expect(isset($error[$attribute]))->toBeTrue("checkstyle <error> has no {$attribute} attribute");
        }

        expect((string) $error['source'])->toBe('a11y-alt-text')
            ->and((string) $error['severity'])->toBe('error');
    });

    it('github: "::error file=...,line=...,col=...::message [ruleId]"', function (): void {
        $line = trim(strtok(formattedOutput('github'), "\n") ?: '');

        expect($line)->toMatch('/^::(error|warning|notice) file=[^,]+,line=\d+,col=\d+::.+ \[[a-z0-9-]+\]$/');
    });

    it('agent: one line, in the Pint-shaped envelope', function (): void {
        $output = formattedOutput('agent');

        expect(substr_count(trim($output), "\n"))->toBe(0);

        $decoded = json_decode($output, true);

        expect($decoded)->toBeArray()
            ->and($decoded['tool'])->toBe('sheath')
            ->and($decoded['result'])->toBe('fail')
            ->and(array_keys($decoded))->toEqualCanonicalizing([
                'tool', 'result', 'errors', 'warnings', 'infos', 'fixable', 'files',
            ]);

        $file = $decoded['files'][0];
        expect($file['path'])->toBe('resources/views/dashboard.blade.php');

        expect(array_keys($file['violations'][0]))->toEqualCanonicalizing([
            'line', 'col', 'severity', 'rule', 'message',
        ]);
    });

    it('agent: a clean run says only that', function (): void {
        $clean = (new Linter(new RuleRegistry))->lint('<p>ok</p>', 'resources/views/ok.blade.php', Config::make());

        expect((new ReporterRegistry)->get('agent')->formatMany([$clean]))
            ->toBe('{"tool":"sheath","result":"passed"}');
    });

    it('agent: info-only findings read "passed", matching the exit code', function (): void {
        $registry = new RuleRegistry;
        $registry->register($rule = new ImgAltTextRule);

        $config = Config::make();
        $config->setRule($rule->getId(), 'info');

        $result = (new Linter($registry))->lint('<img src="a.png">', 'resources/views/a.blade.php', $config);

        $decoded = json_decode((new ReporterRegistry)->get('agent')->formatMany([$result]), true);

        expect($decoded['result'])->toBe('passed')
            ->and($decoded['errors'])->toBe(0)
            ->and($decoded['warnings'])->toBe(0)
            ->and($decoded['infos'])->toBe(1)
            ->and($decoded['files'][0]['violations'])->toHaveCount(1);
    });

    it('agent: warning verdict follows the max-warnings threshold', function (): void {
        $registry = new RuleRegistry;
        $registry->register($rule = new ImgAltTextRule);

        $config = Config::make();
        $config->setRule($rule->getId(), 'warning');
        $result = (new Linter($registry))->lint('<img src="a.png">', 'resources/views/a.blade.php', $config);

        $reporter = new AgentReporter;

        expect(json_decode($reporter->formatMany([$result]), true)['result'])->toBe('passed')
            ->and(json_decode($reporter->setMaxWarnings(0)->formatMany([$result]), true)['result'])->toBe('fail')
            ->and(json_decode($reporter->setMaxWarnings(1)->formatMany([$result]), true)['result'])->toBe('passed');
    });

    it('agent: marks a fixable violation, and a dangerous one distinctly', function (): void {
        $registry = new RuleRegistry;
        $registry->register($safe = new NoTargetBlankRule);
        $registry->register($dangerous = new PreferLangHelperRule);

        $config = Config::make();
        foreach ([$safe, $dangerous] as $rule) {
            $config->setRule($rule->getId(), ['severity' => $rule->getDefaultSeverity()->value, 'options' => []]);
        }

        $result = (new Linter($registry))->lint(
            "<a href=\"https://x.test\" target=\"_blank\" rel=\"opener\">go</a>\n@lang('a.b')",
            'resources/views/dashboard.blade.php',
            $config
        );

        $decoded = json_decode((new ReporterRegistry)->get('agent')->formatMany([$result]), true);
        $byRule = array_column($decoded['files'][0]['violations'], 'fixable', 'rule');

        expect($byRule['security-no-target-blank'])->toBeTrue()
            ->and($byRule['blade-prefer-lang-helper'])->toBe('dangerous');
    });

    it('counts infos in the json summary', function (): void {
        $registry = new RuleRegistry;
        $registry->register($rule = new ImgAltTextRule);

        $config = Config::make();
        $config->setRule($rule->getId(), 'info');

        $result = (new Linter($registry))->lint('<img src="a.png">', 'resources/views/a.blade.php', $config);
        $decoded = json_decode((new ReporterRegistry)->get('json')->formatMany([$result]), true);

        expect($decoded['summary']['infos'])->toBe(1)
            ->and($decoded['results'][0]['infoCount'])->toBe(1)
            ->and($decoded['summary']['errors'])->toBe(0)
            ->and($decoded['summary']['warnings'])->toBe(0);
    });

    it('emits project-relative paths in every format', function (string $format): void {
        $registry = new RuleRegistry;
        $registry->register($rule = new ImgAltTextRule);

        $config = Config::make();
        $config->setRule($rule->getId(), 'error');

        $absolute = base_path('resources/views/pages/home.blade.php');
        $result = (new Linter($registry))->lint('<img src="a.png">', $absolute, $config);

        $output = (new ReporterRegistry)->get($format)->formatMany([$result]);

        expect($output)->toContain('resources/views/pages/home.blade.php')
            ->and($output)->not->toContain(str_replace('/', '\\', base_path()));

        $normalizedRoot = str_replace('\\', '/', base_path());
        expect(str_contains($output, $normalizedRoot))->toBeFalse("{$format} still contains the absolute project root");
    })->with(['json', 'agent', 'checkstyle', 'compact', 'unix', 'github', 'stylish']);

    it('registers exactly the formats --format offers', function (): void {
        preg_match(
            '/--format=\w+ : [^(]*\(([^)]+)\)/',
            (string) file_get_contents(__DIR__.'/../../../src/Console/LintCommand.php'),
            $help
        );

        $offered = array_map(trim(...), explode(',', $help[1] ?? ''));

        expect($offered)->toEqualCanonicalizing((new ReporterRegistry)->getAvailableReporters());
    });
});
