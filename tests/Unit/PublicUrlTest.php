<?php

use App\Support\PublicUrl;

test('public IP literals are allowed', function () {
    expect(PublicUrl::isPublic('https://8.8.8.8/webhook'))->toBeTrue()
        ->and(PublicUrl::isPublic('http://1.1.1.1/hook'))->toBeTrue();
});

test('private, loopback, and reserved addresses are blocked', function () {
    expect(PublicUrl::isPublic('http://127.0.0.1/x'))->toBeFalse()
        ->and(PublicUrl::isPublic('http://10.0.0.1/x'))->toBeFalse()
        ->and(PublicUrl::isPublic('http://192.168.0.1/x'))->toBeFalse()
        ->and(PublicUrl::isPublic('http://172.16.0.1/x'))->toBeFalse()
        ->and(PublicUrl::isPublic('http://169.254.169.254/latest/meta-data/'))->toBeFalse()
        ->and(PublicUrl::isPublic('http://[::1]/x'))->toBeFalse();
});

test('non-http schemes and malformed URLs are blocked', function () {
    expect(PublicUrl::isPublic('ftp://8.8.8.8/x'))->toBeFalse()
        ->and(PublicUrl::isPublic('file:///etc/passwd'))->toBeFalse()
        ->and(PublicUrl::isPublic('not a url'))->toBeFalse()
        ->and(PublicUrl::isPublic(''))->toBeFalse();
});
