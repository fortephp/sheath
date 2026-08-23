<?php

declare(strict_types=1);

use Forte\Sheath\Parsing\ComponentAttributeBagExpression;

final class ReflectedAttributeBagFixture
{
    public function nativeBag(): self
    {
        return $this;
    }

    /** @return static */
    public function documentedBag()
    {
        return $this;
    }

    public function nullableBag(bool $returnBag = true): ?self
    {
        return $returnBag ? $this : null;
    }

    public function unionBag(bool $returnBag = true): self|string
    {
        return $returnBag ? $this : '';
    }

    public function scalar(): string
    {
        return '';
    }

    /** @return mixed */
    public function documentedMixed()
    {
        return $this;
    }

    public static function staticBag(): self
    {
        return new self;
    }
}

/** @return list<string> */
function reflectedAttributeBagMethods(string $class): array
{
    $method = new ReflectionMethod(ComponentAttributeBagExpression::class, 'reflectedBagReturningMethods');

    /** @var list<string> $methods */
    $methods = $method->invoke(null, $class);

    return $methods;
}

it('augments known methods only when reflection proves they return the bag', function (): void {
    $methods = reflectedAttributeBagMethods(ReflectedAttributeBagFixture::class);

    expect($methods)->toContain('nativebag', 'documentedbag');
    expect(array_intersect([
        'nullablebag',
        'unionbag',
        'scalar',
        'documentedmixed',
        'staticbag',
    ], $methods))->toBe([]);
});

it('fails closed when the reflected bag class is unavailable', function (): void {
    expect(reflectedAttributeBagMethods('Missing\\ComponentAttributeBag'))->toBe([]);
});
