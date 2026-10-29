<?php

use App\Support\ByteSizeParser;

test('size values with units are converted to bytes', function () {
    expect(ByteSizeParser::parse('100MB'))->toBe(104857600);
    expect(ByteSizeParser::parse('1.5 GB'))->toBe(1610612736);
    expect(ByteSizeParser::parse('1024'))->toBe(1024);
    expect(ByteSizeParser::parse('0.5KB'))->toBe(512);
});

test('invalid or out of range size values are rejected', function () {
    expect(ByteSizeParser::parse('100 MB extra'))->toBeNull();
    expect(ByteSizeParser::parse('-1MB'))->toBeNull();
    expect(ByteSizeParser::parse('999999999999999999999TB'))->toBeNull();
});
