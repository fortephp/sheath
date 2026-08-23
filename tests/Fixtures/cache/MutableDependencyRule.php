<?php

declare(strict_types=1);

namespace Forte\Sheath\Tests\Fixtures\Cache;

use Forte\Ast\Document\Document;
use Forte\Sheath\Rules\RuleContext;

final class MutableDependencyRule extends MutableRuleBase
{
    use MutableRuleTrait;

    public function getId(): string
    {
        return 'mutable-dependency-cache-probe';
    }

    public function getDescription(): string
    {
        return 'Rule used to verify inherited source-aware cache invalidation.';
    }

    public function check(Document $document, RuleContext $context): void
    {
        $this->sharedBehavior();
    }
}
