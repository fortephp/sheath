<?php

declare(strict_types=1);

namespace Forte\Sheath\Parsing;

use Forte\Parser\ParserOptions;

/** @internal */
final class BladeParserOptions
{
    private const LINTER_ELEMENT_DEPTH_LIMIT = 2048;

    private const LINTER_DIRECTIVE_DEPTH_LIMIT = 1024;

    private const LINTER_CONDITION_DEPTH_LIMIT = 1024;

    public static function normalize(?ParserOptions $options): ParserOptions
    {
        $options = clone ($options ?? ParserOptions::defaults());

        if (! $options->hasCustomDepthLimits()) {
            $options->depthLimits(
                elements: self::LINTER_ELEMENT_DEPTH_LIMIT,
                directives: self::LINTER_DIRECTIVE_DEPTH_LIMIT,
                conditions: self::LINTER_CONDITION_DEPTH_LIMIT,
            );
        }

        // ParserOptions clones are shallow. Isolate the mutable component and
        // directive registries so compatibility additions do not mutate
        // caller-owned options or Forte's shared defaults template.
        $options->components(clone $options->getComponentManager());
        $directives = clone $options->getDirectives();
        $directives->registerDirective('fonts');
        $directives->loadJson(<<<'JSON'
            [
              {
                "name": "teleport",
                "args": true,
                "structure": {
                  "role": "open",
                  "terminators": "endteleport"
                }
              },
              {
                "name": "endteleport",
                "args": false,
                "structure": {
                  "role": "close"
                }
              }
            ]
            JSON);
        $options->directives($directives);
        $options->withComponentPrefix('x:');

        return $options;
    }
}
