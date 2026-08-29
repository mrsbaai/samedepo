<?php

use App\Http\Middleware\TrustCloudflare;
use App\Security\Blocklist\IpBlocklist;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

test('it resolves the real client IP from CF-Connecting-IP when behind Cloudflare', function () {
    $request = Request::create('http://samedepo.test/signin', 'GET', [], [], [], [
        'REMOTE_ADDR' => '104.16.0.99',
        'HTTP_CF_CONNECTING_IP' => '171.225.184.136',
    ]);

    $middleware = new TrustCloudflare;
    $middleware->handle($request, fn (Request $r): Response => new Response('ok'));

    expect($request->ip())->toBe('171.225.184.136');
});

test('it ignores CF-Connecting-IP when the connection is not from a Cloudflare IP', function () {
    $request = Request::create('http://samedepo.test/signin', 'GET', [], [], [], [
        'REMOTE_ADDR' => '192.0.2.1',
        'HTTP_CF_CONNECTING_IP' => '1.2.3.4',
    ]);

    $middleware = new TrustCloudflare;
    $middleware->handle($request, fn (Request $r): Response => new Response('ok'));

    expect($request->ip())->toBe('192.0.2.1');
});

test('it falls back to the connection IP when CF-Connecting-IP is missing', function () {
    $request = Request::create('http://samedepo.test/signin', 'GET', [], [], [], [
        'REMOTE_ADDR' => '104.16.0.99',
    ]);

    $middleware = new TrustCloudflare;
    $middleware->handle($request, fn (Request $r): Response => new Response('ok'));

    expect($request->ip())->toBe('104.16.0.99');
});

test('it validates IPv6 Cloudflare edge addresses', function () {
    $request = Request::create('http://samedepo.test/signin', 'GET', [], [], [], [
        'REMOTE_ADDR' => '2606:4700::6810:7b5',
        'HTTP_CF_CONNECTING_IP' => '2001:db8::1',
    ]);

    $middleware = new TrustCloudflare;
    $middleware->handle($request, fn (Request $r): Response => new Response('ok'));

    expect($request->ip())->toBe('2001:db8::1');
});

test('the security blocklist applies to the real client IP, not the Cloudflare proxy', function () {
    IpBlocklist::block('171.225.184.136', 'test');

    $this->withServerVariables(['REMOTE_ADDR' => '104.16.0.99'])
        ->withHeaders(['CF-Connecting-IP' => '171.225.184.136'])
        ->get('/signin')
        ->assertForbidden();
});

test('a spoofed CF-Connecting-IP from a non-Cloudflare address cannot block someone else', function () {
    IpBlocklist::block('1.2.3.4', 'test');

    $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.1'])
        ->withHeaders(['CF-Connecting-IP' => '1.2.3.4'])
        ->get('/signin')
        ->assertOk();
});
