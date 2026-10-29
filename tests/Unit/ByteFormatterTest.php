<?php

use App\Support\ByteFormatter;

test('byte sizes use readable units without converting smaller values', function () {
    expect(ByteFormatter::format(0))->toBe('0 B');
    expect(ByteFormatter::format(1023))->toBe('1,023 B');
    expect(ByteFormatter::format(1024 ** 2 - 1))->toBe('1,048,575 B');
    expect(ByteFormatter::format(1024 ** 2))->toBe('1 MB');
    expect(ByteFormatter::format(1024 ** 2 * 1.5))->toBe('1.5 MB');
    expect(ByteFormatter::format(1024 ** 3 - 1))->toBe('1 GB');
    expect(ByteFormatter::format(1024 ** 3))->toBe('1 GB');
    expect(ByteFormatter::format(11_315_634_542))->toBe('10.54 GB');
});
