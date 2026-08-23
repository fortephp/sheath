<?php

declare(strict_types=1);

use Forte\Sheath\Serialization\InternalDataCodec;

it('preserves valid JSON-shaped data and round trips binary strings', function (): void {
    $data = [
        'valid' => 'hello 🙂',
        'nested' => ['binary' => "a\xFFb"],
        'number' => 42,
        'flag' => true,
        'nothing' => null,
    ];

    $encoded = InternalDataCodec::encode($data);

    expect(json_encode($encoded, JSON_THROW_ON_ERROR))->toBeString()
        ->and(InternalDataCodec::decode($encoded))->toBe($data);
});

it('rejects malformed binary markers', function (): void {
    expect(fn () => InternalDataCodec::decode([
        'value' => ['__sheath_binary_base64_v1' => '%%%'],
    ]))->toThrow(InvalidArgumentException::class);
});
