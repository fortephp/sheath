<?php

declare(strict_types=1);

use Forte\Sheath\Configuration\Config;
use Forte\Sheath\Linter;
use Forte\Sheath\Reporters\AgentReporter;
use Forte\Sheath\Reporters\CheckstyleReporter;
use Forte\Sheath\Reporters\CompactReporter;
use Forte\Sheath\Reporters\GitHubReporter;
use Forte\Sheath\Reporters\JsonReporter;
use Forte\Sheath\Reporters\StylishReporter;
use Forte\Sheath\Reporters\UnixReporter;
use Forte\Sheath\Results\LintResult;
use Forte\Sheath\Results\Position;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Results\Violation;
use Forte\Sheath\Rules\BestPractices\Attributes\ButtonTypeRule;
use Forte\Sheath\Rules\RuleRegistry;

function violationWith(string $message, string $file = 'resources/views/page.blade.php'): LintResult
{
    return new LintResult($file, [
        new Violation('demo-rule', $message, Severity::ERROR, $file, new Position(0, 3, 5), new Position(9, 3, 14)),
    ]);
}

describe('violation messages are single-line', function (): void {
    it('collapses a line break into a space', function (): void {
        expect(violationWith("Invalid button type 'sub\nmit'.")->violations[0]->message)
            ->toBe("Invalid button type 'sub mit'.");
    });

    it('collapses a CRLF break', function (): void {
        expect(violationWith("First\r\nSecond")->violations[0]->message)->toBe('First Second');
    });

    it('collapses whitespace around the break', function (): void {
        expect(violationWith("First   \n\t  Second")->violations[0]->message)->toBe('First Second');
    });

    it('trims the result', function (): void {
        expect(violationWith("\n  Message  \n")->violations[0]->message)->toBe('Message');
    });

    it('leaves an ordinary message untouched', function (): void {
        expect(violationWith('Buttons should have an explicit type attribute')->violations[0]->message)
            ->toBe('Buttons should have an explicit type attribute');
    });

    it('substitutes malformed UTF-8 without erasing the diagnostic', function (): void {
        $message = violationWith("Invalid attribute \xFF value\non next line")->violations[0]->message;

        expect($message)->toBe("Invalid attribute \u{FFFD} value on next line")
            ->and(preg_match('//u', $message))->toBe(1);
    });
});

describe('line-oriented formats stay line-oriented', function (): void {
    it('emits exactly one line per violation', function (string $reporterClass): void {
        /** @var CompactReporter|UnixReporter|GitHubReporter $reporter */
        $reporter = new $reporterClass;

        $output = $reporter->format(violationWith("Broken\nacross\nlines"));

        expect(substr_count($output, "\n"))->toBe(0);
    })->with([CompactReporter::class, UnixReporter::class, GitHubReporter::class]);

    it('reversibly escapes control characters in paths without adding records', function (string $reporterClass): void {
        /** @var CompactReporter|UnixReporter $reporter */
        $reporter = new $reporterClass;

        $output = $reporter->format(violationWith('Broken', "views/tab\tline\nbreak\rreturn\x1Bescape.blade.php"));

        expect($output)->toContain('views/tab\\tline\\nbreak\\rreturn\\x1Bescape.blade.php')
            ->and(substr_count($output, "\n"))->toBe(0);
    })->with([CompactReporter::class, UnixReporter::class]);

    it('keeps a stylish path header on one line', function (): void {
        $output = (new StylishReporter)->format(violationWith('Broken', "views/line\nbreak.blade.php"));

        expect($output)->toStartWith('<options=bold>views/line\\nbreak.blade.php</>')
            ->and(substr_count($output, "\n"))->toBe(3);
    });
});

describe('the GitHub reporter escapes workflow commands', function (): void {
    it('escapes a percent sign in the message', function (): void {
        expect((new GitHubReporter)->format(violationWith('Width must not be 100% here')))
            ->toContain('100%25 here');
    });

    it('escapes a comma in the file path', function (): void {
        $output = (new GitHubReporter)->format(violationWith('Something', 'views/a,b.blade.php'));

        expect($output)->toContain('file=views/a%2Cb.blade.php,line=3')
            ->and($output)->not->toContain('file=views/a,b.blade.php');
    });

    it('escapes a colon in the file path', function (): void {
        expect((new GitHubReporter)->format(violationWith('Something', 'C:/views/page.blade.php')))
            ->toContain('file=C%3A/views/page.blade.php,');
    });

    it('keeps the command on one line', function (): void {
        $output = (new GitHubReporter)->format(violationWith("First\nSecond"));

        expect($output)->toStartWith('::error ')
            ->and(substr_count($output, "\n"))->toBe(0);
    });

    it('escapes workflow-command data in custom rule IDs', function (): void {
        $result = new LintResult('resources/views/page.blade.php', [
            new Violation(
                "custom%rule\r\n::error::injected",
                'Ordinary message',
                Severity::WARNING,
                'resources/views/page.blade.php',
                new Position(0, 1, 1),
                new Position(1, 1, 2),
            ),
        ]);

        $output = (new GitHubReporter)->format($result);

        expect($output)->toBe('::warning file=resources/views/page.blade.php,line=1,col=1::Ordinary message [custom%25rule%0D%0A::error::injected]')
            ->and(substr_count($output, "\n"))->toBe(0);
    });

    it('still reads normally for an ordinary message', function (): void {
        expect((new GitHubReporter)->format(violationWith('Images must have an alt attribute.')))
            ->toBe('::error file=resources/views/page.blade.php,line=3,col=5::Images must have an alt attribute. [demo-rule]');
    });
});

describe('the checkstyle reporter escapes XML', function (): void {
    it('escapes markup in a message', function (): void {
        $output = (new CheckstyleReporter)->format(violationWith('Use <strong> & "quotes"'));

        expect($output)->toContain('&lt;strong&gt; &amp; &quot;quotes&quot;')
            ->and($output)->not->toContain('<strong>');
    });

    it('produces parseable XML for a hostile message', function (): void {
        $xml = (new CheckstyleReporter)->formatMany([
            violationWith('Bad: <tag attr="v"> & \'more\''),
        ]);

        $previous = libxml_use_internal_errors(true);
        $parsed = simplexml_load_string($xml);
        libxml_use_internal_errors($previous);

        expect($parsed)->not->toBeFalse();
    });

    it('substitutes malformed UTF-8 in file paths and remains parseable', function (): void {
        $xml = (new CheckstyleReporter)->formatMany([
            violationWith("Invalid \xFF value", "views/invalid-\xFF.blade.php"),
        ]);

        $previous = libxml_use_internal_errors(true);
        $parsed = simplexml_load_string($xml);
        libxml_use_internal_errors($previous);

        expect($parsed)->not->toBeFalse()
            ->and($xml)->toContain("views/invalid-\u{FFFD}.blade.php")
            ->and($xml)->toContain("Invalid \u{FFFD} value");
    });

    it('replaces XML-forbidden controls from linted content and file paths', function (): void {
        $rule = new ButtonTypeRule;
        $registry = new RuleRegistry;
        $registry->register($rule);
        $config = Config::make();
        $config->setRule($rule->getId(), $rule->getDefaultSeverity()->value);

        $result = (new Linter($registry))->lint(
            "<button type=\"bogus\x01\">x</button>",
            "views/control-\x02.blade.php",
            $config,
        );
        $xml = (new CheckstyleReporter)->formatMany([$result]);

        $previous = libxml_use_internal_errors(true);
        $parsed = simplexml_load_string($xml);
        libxml_use_internal_errors($previous);

        expect($parsed)->not->toBeFalse()
            ->and($xml)->not->toContain("\x01")
            ->and($xml)->not->toContain("\x02")
            ->and(substr_count($xml, "\u{FFFD}"))->toBeGreaterThanOrEqual(2);
    });

    it('preserves the XML whitespace controls', function (): void {
        $xml = (new CheckstyleReporter)->formatMany([
            violationWith("Allowed\twhitespace", "views/line\rreturn.blade.php"),
        ]);

        $previous = libxml_use_internal_errors(true);
        $parsed = simplexml_load_string($xml);
        libxml_use_internal_errors($previous);

        expect($parsed)->not->toBeFalse()
            ->and((string) $parsed->file['name'])->toBe("views/line\rreturn.blade.php");
    });

    it('preserves path controls exactly through XML parsing', function (): void {
        $path = "views/tab\tline\nbreak\rreturn.blade.php";
        $xml = (new CheckstyleReporter)->formatMany([violationWith('Allowed', $path)]);

        $parsed = simplexml_load_string($xml);

        expect($parsed)->not->toBeFalse()
            ->and((string) $parsed->file['name'])->toBe($path)
            ->and($xml)->toContain('&#x9;')
            ->and($xml)->toContain('&#xA;')
            ->and($xml)->toContain('&#xD;');
    });
});

describe('the json reporter stays valid json', function (): void {
    it('encodes a hostile message', function (): void {
        $output = (new JsonReporter)->format(violationWith("Quote \" backslash \\ newline\nhere"));

        expect(json_decode($output, true))->toBeArray();
    });

    it('substitutes invalid UTF-8 instead of returning an empty document', function (string $reporterClass): void {
        /** @var AgentReporter|JsonReporter $reporter */
        $reporter = new $reporterClass;
        $output = $reporter->format(violationWith('Something', "views/invalid-\xB1.blade.php"));

        expect($output)->not->toBe('')
            ->and(json_decode($output, true, flags: JSON_THROW_ON_ERROR))->toBeArray();
    })->with([JsonReporter::class, AgentReporter::class]);

    it('continues to preserve path controls as JSON string data', function (): void {
        $path = "views/tab\tline\nbreak\rreturn.blade.php";
        $decoded = json_decode(
            (new JsonReporter)->format(violationWith('Something', $path)),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        expect($decoded['results'][0]['filePath'])->toBe($path)
            ->and($decoded['results'][0]['violations'][0]['filePath'])->toBe($path);
    });
});
