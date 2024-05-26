<?php

namespace React\Tests\Http\Message;

use React\Http\Message\Uri;
use React\Tests\Http\TestCase;

class UriTest extends TestCase
{
    public function testCtorWithInvalidSyntaxThrows()
    {
        $this->expectException(\InvalidArgumentException::class);
        new Uri('///');
    }

    public function testCtorWithInvalidSchemeThrows()
    {
        $this->expectException(\InvalidArgumentException::class);
        new Uri('not+a+scheme://localhost');
    }

    public function testCtorWithInvalidHostThrows()
    {
        $this->expectException(\InvalidArgumentException::class);
        new Uri('http://not a host/');
    }

    public function testCtorWithInvalidPortThrows()
    {
        $this->expectException(\InvalidArgumentException::class);
        new Uri('http://localhost:80000/');
    }

    public static function provideValidUris()
    {
        yield [
            'http://localhost'
        ];
        yield [
            'http://localhost/'
        ];
        yield [
            'http://localhost:8080/'
        ];
        yield [
            'http://127.0.0.1/'
        ];
        yield [
            'http://[::1]:8080/'
        ];
        yield [
            'http://localhost/path'
        ];
        yield [
            'http://localhost/sub/path'
        ];
        yield [
            'http://localhost/with%20space'
        ];
        yield [
            'http://localhost/with%2fslash'
        ];
        yield [
            'http://localhost/?name=Alice'
        ];
        yield [
            'http://localhost/?name=John+Doe'
        ];
        yield [
            'http://localhost/?name=John%20Doe'
        ];
        yield [
            'http://localhost/?name=Alice&age=42'
        ];
        yield [
            'http://localhost/?name=Alice&'
        ];
        yield [
            'http://localhost/?choice=A%26B'
        ];
        yield [
            'http://localhost/?safe=Yes!?'
        ];
        yield [
            'http://localhost/?alias=@home'
        ];
        yield [
            'http://localhost/?assign:=true'
        ];
        yield [
            'http://localhost/?name='
        ];
        yield [
            'http://localhost/?name'
        ];
        yield [
            ''
        ];
        yield [
            '/'
        ];
        yield [
            '/path'
        ];
        yield [
            'path'
        ];
        yield [
            'http://user@localhost/'
        ];
        yield [
            'http://user:@localhost/'
        ];
        yield [
            'http://:pass@localhost/'
        ];
        yield [
            'http://user:pass@localhost/path?query#fragment'
        ];
        yield [
            'http://user%20name:pass%20word@localhost/path%20name?query%20name#frag%20ment'
        ];
    }

    /**
     * @dataProvider provideValidUris
     * @param string $string
     */
    public function testToStringReturnsOriginalUriGivenToCtor($string)
    {
        $uri = new Uri($string);

        $this->assertEquals($string, (string) $uri);
    }

    public static function provideValidUrisThatWillBeTransformed()
    {
        yield [
            'http://localhost:8080/?',
            'http://localhost:8080/'
        ];
        yield [
            'http://localhost:8080/#',
            'http://localhost:8080/'
        ];
        yield [
            'http://localhost:8080/?#',
            'http://localhost:8080/'
        ];
        yield [
            'http://@localhost:8080/',
            'http://localhost:8080/'
        ];
        yield [
            'http://localhost:8080/?percent=50%',
            'http://localhost:8080/?percent=50%25'
        ];
        yield [
            'http://user name:pass word@localhost/path name?query name#frag ment',
            'http://user%20name:pass%20word@localhost/path%20name?query%20name#frag%20ment'
        ];
        yield [
            'HTTP://USER:PASS@LOCALHOST:8080/PATH?QUERY#FRAGMENT',
            'http://USER:PASS@localhost:8080/PATH?QUERY#FRAGMENT'
        ];
    }

    /**
     * @dataProvider provideValidUrisThatWillBeTransformed
     * @param string $string
     * @param string $escaped
     */
    public function testToStringReturnsTransformedUriFromUriGivenToCtor($string, $escaped = null)
    {
        $uri = new Uri($string);

        $this->assertEquals($escaped, (string) $uri);
    }

    public function testToStringReturnsUriWithPathPrefixedWithSlashWhenPathDoesNotStartWithSlash()
    {
        $uri = new Uri('http://localhost:8080');
        $uri = $uri->withPath('path');

        $this->assertEquals('http://localhost:8080/path', (string) $uri);
    }

    public function testWithSchemeReturnsNewInstanceWhenSchemeIsChanged()
    {
        $uri = new Uri('http://localhost');

        $new = $uri->withScheme('https');
        $this->assertNotSame($uri, $new);
        $this->assertEquals('https', $new->getScheme());
        $this->assertEquals('http', $uri->getScheme());
    }

    public function testWithSchemeReturnsNewInstanceWithSchemeToLowerCaseWhenSchemeIsChangedWithUpperCase()
    {
        $uri = new Uri('http://localhost');

        $new = $uri->withScheme('HTTPS');
        $this->assertNotSame($uri, $new);
        $this->assertEquals('https', $new->getScheme());
        $this->assertEquals('http', $uri->getScheme());
    }

    public function testWithSchemeReturnsNewInstanceWithDefaultPortRemovedWhenSchemeIsChangedToDefaultPortForHttp()
    {
        $uri = new Uri('https://localhost:80');

        $new = $uri->withScheme('http');
        $this->assertNotSame($uri, $new);
        $this->assertNull($new->getPort());
        $this->assertEquals(80, $uri->getPort());
    }

    public function testWithSchemeReturnsNewInstanceWithDefaultPortRemovedWhenSchemeIsChangedToDefaultPortForHttps()
    {
        $uri = new Uri('http://localhost:443');

        $new = $uri->withScheme('https');
        $this->assertNotSame($uri, $new);
        $this->assertNull($new->getPort());
        $this->assertEquals(443, $uri->getPort());
    }

    public function testWithSchemeReturnsSameInstanceWhenSchemeIsUnchanged()
    {
        $uri = new Uri('http://localhost');

        $new = $uri->withScheme('http');
        $this->assertSame($uri, $new);
        $this->assertEquals('http', $uri->getScheme());
    }

    public function testWithSchemeReturnsSameInstanceWhenSchemeToLowerCaseIsUnchanged()
    {
        $uri = new Uri('http://localhost');

        $new = $uri->withScheme('HTTP');
        $this->assertSame($uri, $new);
        $this->assertEquals('http', $uri->getScheme());
    }

    public function testWithSchemeThrowsWhenSchemeIsInvalid()
    {
        $uri = new Uri('http://localhost');

        $this->expectException(\InvalidArgumentException::class);
        $uri->withScheme('invalid+scheme');
    }

    public function testWithUserInfoReturnsNewInstanceWhenUserInfoIsChangedWithNameAndPassword()
    {
        $uri = new Uri('http://localhost');

        $new = $uri->withUserInfo('user', 'pass');
        $this->assertNotSame($uri, $new);
        $this->assertEquals('user:pass', $new->getUserInfo());
        $this->assertEquals('', $uri->getUserInfo());
    }

    public function testWithUserInfoReturnsNewInstanceWhenUserInfoIsChangedWithNameOnly()
    {
        $uri = new Uri('http://localhost');

        $new = $uri->withUserInfo('user');
        $this->assertNotSame($uri, $new);
        $this->assertEquals('user', $new->getUserInfo());
        $this->assertEquals('', $uri->getUserInfo());
    }

    public function testWithUserInfoReturnsNewInstanceWhenUserInfoIsChangedWithNameAndEmptyPassword()
    {
        $uri = new Uri('http://localhost');

        $new = $uri->withUserInfo('user', '');
        $this->assertNotSame($uri, $new);
        $this->assertEquals('user:', $new->getUserInfo());
        $this->assertEquals('', $uri->getUserInfo());
    }

    public function testWithUserInfoReturnsNewInstanceWhenUserInfoIsChangedWithPasswordOnly()
    {
        $uri = new Uri('http://localhost');

        $new = $uri->withUserInfo('', 'pass');
        $this->assertNotSame($uri, $new);
        $this->assertEquals(':pass', $new->getUserInfo());
        $this->assertEquals('', $uri->getUserInfo());
    }

    public function testWithUserInfoReturnsNewInstanceWhenUserInfoIsChangedWithNameAndPasswordEncoded()
    {
        $uri = new Uri('http://localhost');

        $new = $uri->withUserInfo('user:alice', 'pass%20word');
        $this->assertNotSame($uri, $new);
        $this->assertEquals('user%3Aalice:pass%20word', $new->getUserInfo());
        $this->assertEquals('', $uri->getUserInfo());
    }

    public function testWithSchemeReturnsSameInstanceWhenSchemeIsUnchangedEmpty()
    {
        $uri = new Uri('http://localhost');

        $new = $uri->withUserInfo('');
        $this->assertSame($uri, $new);
        $this->assertEquals('', $uri->getUserInfo());
    }

    public function testWithSchemeReturnsSameInstanceWhenSchemeIsUnchangedWithNameAndPassword()
    {
        $uri = new Uri('http://user:pass@localhost');

        $new = $uri->withUserInfo('user', 'pass');
        $this->assertSame($uri, $new);
        $this->assertEquals('user:pass', $uri->getUserInfo());
    }

    public function testWithHostReturnsNewInstanceWhenHostIsChanged()
    {
        $uri = new Uri('http://localhost');

        $new = $uri->withHost('example.com');
        $this->assertNotSame($uri, $new);
        $this->assertEquals('example.com', $new->getHost());
        $this->assertEquals('localhost', $uri->getHost());
    }

    public function testWithHostReturnsNewInstanceWithHostToLowerCaseWhenHostIsChangedWithUpperCase()
    {
        $uri = new Uri('http://localhost');

        $new = $uri->withHost('EXAMPLE.COM');
        $this->assertNotSame($uri, $new);
        $this->assertEquals('example.com', $new->getHost());
        $this->assertEquals('localhost', $uri->getHost());
    }

    public function testWithHostReturnsNewInstanceWhenHostIsChangedToEmptyString()
    {
        $uri = new Uri('http://localhost');

        $new = $uri->withHost('');
        $this->assertNotSame($uri, $new);
        $this->assertEquals('', $new->getHost());
        $this->assertEquals('localhost', $uri->getHost());
    }

    public function testWithHostReturnsSameInstanceWhenHostIsUnchanged()
    {
        $uri = new Uri('http://localhost');

        $new = $uri->withHost('localhost');
        $this->assertSame($uri, $new);
        $this->assertEquals('localhost', $uri->getHost());
    }

    public function testWithHostReturnsSameInstanceWhenHostToLowerCaseIsUnchanged()
    {
        $uri = new Uri('http://localhost');

        $new = $uri->withHost('LOCALHOST');
        $this->assertSame($uri, $new);
        $this->assertEquals('localhost', $uri->getHost());
    }

    public function testWithHostThrowsWhenHostIsInvalidWithPlus()
    {
        $uri = new Uri('http://localhost');

        $this->expectException(\InvalidArgumentException::class);
        $uri->withHost('invalid+host');
    }

    public function testWithHostThrowsWhenHostIsInvalidWithSpace()
    {
        $uri = new Uri('http://localhost');

        $this->expectException(\InvalidArgumentException::class);
        $uri->withHost('invalid host');
    }

    public function testWithPortReturnsNewInstanceWhenPortIsChanged()
    {
        $uri = new Uri('http://localhost');

        $new = $uri->withPort(8080);
        $this->assertNotSame($uri, $new);
        $this->assertEquals(8080, $new->getPort());
        $this->assertNull($uri->getPort());
    }

    public function testWithPortReturnsNewInstanceWithDefaultPortRemovedWhenPortIsChangedToDefaultPortForHttp()
    {
        $uri = new Uri('http://localhost:8080');

        $new = $uri->withPort(80);
        $this->assertNotSame($uri, $new);
        $this->assertNull($new->getPort());
        $this->assertEquals(8080, $uri->getPort());
    }

    public function testWithPortReturnsNewInstanceWithDefaultPortRemovedWhenPortIsChangedToDefaultPortForHttps()
    {
        $uri = new Uri('https://localhost:8080');

        $new = $uri->withPort(443);
        $this->assertNotSame($uri, $new);
        $this->assertNull($new->getPort());
        $this->assertEquals(8080, $uri->getPort());
    }

    public function testWithPortReturnsSameInstanceWhenPortIsUnchanged()
    {
        $uri = new Uri('http://localhost:8080');

        $new = $uri->withPort(8080);
        $this->assertSame($uri, $new);
        $this->assertEquals(8080, $uri->getPort());
    }

    public function testWithPortReturnsSameInstanceWhenPortIsUnchangedDefaultPortForHttp()
    {
        $uri = new Uri('http://localhost');

        $new = $uri->withPort(80);
        $this->assertSame($uri, $new);
        $this->assertNull($uri->getPort());
    }

    public function testWithPortReturnsSameInstanceWhenPortIsUnchangedDefaultPortForHttps()
    {
        $uri = new Uri('https://localhost');

        $new = $uri->withPort(443);
        $this->assertSame($uri, $new);
        $this->assertNull($uri->getPort());
    }

    public function testWithPortThrowsWhenPortIsInvalidUnderflow()
    {
        $uri = new Uri('http://localhost');

        $this->expectException(\InvalidArgumentException::class);
        $uri->withPort(0);
    }

    public function testWithPortThrowsWhenPortIsInvalidOverflow()
    {
        $uri = new Uri('http://localhost');

        $this->expectException(\InvalidArgumentException::class);
        $uri->withPort(65536);
    }

    public function testWithPathReturnsNewInstanceWhenPathIsChanged()
    {
        $uri = new Uri('http://localhost/');

        $new = $uri->withPath('/path');
        $this->assertNotSame($uri, $new);
        $this->assertEquals('/path', $new->getPath());
        $this->assertEquals('/', $uri->getPath());
    }

    public function testWithPathReturnsNewInstanceWhenPathIsChangedEncoded()
    {
        $uri = new Uri('http://localhost/');

        $new = $uri->withPath('/a new/path%20here!');
        $this->assertNotSame($uri, $new);
        $this->assertEquals('/a%20new/path%20here!', $new->getPath());
        $this->assertEquals('/', $uri->getPath());
    }

    public function testWithPathReturnsSameInstanceWhenPathIsUnchanged()
    {
        $uri = new Uri('http://localhost/path');

        $new = $uri->withPath('/path');
        $this->assertSame($uri, $new);
        $this->assertEquals('/path', $uri->getPath());
    }

    public function testWithPathReturnsSameInstanceWhenPathIsUnchangedEncoded()
    {
        $uri = new Uri('http://localhost/a%20new/path%20here!');

        $new = $uri->withPath('/a new/path%20here!');
        $this->assertSame($uri, $new);
        $this->assertEquals('/a%20new/path%20here!', $uri->getPath());
    }

    public function testWithQueryReturnsNewInstanceWhenQueryIsChanged()
    {
        $uri = new Uri('http://localhost');

        $new = $uri->withQuery('foo=bar');
        $this->assertNotSame($uri, $new);
        $this->assertEquals('foo=bar', $new->getQuery());
        $this->assertEquals('', $uri->getQuery());
    }

    public function testWithQueryReturnsNewInstanceWhenQueryIsChangedEncoded()
    {
        $uri = new Uri('http://localhost');

        $new = $uri->withQuery('foo=a new%20text!');
        $this->assertNotSame($uri, $new);
        $this->assertEquals('foo=a%20new%20text!', $new->getQuery());
        $this->assertEquals('', $uri->getQuery());
    }

    public function testWithQueryReturnsSameInstanceWhenQueryIsUnchanged()
    {
        $uri = new Uri('http://localhost?foo=bar');

        $new = $uri->withQuery('foo=bar');
        $this->assertSame($uri, $new);
        $this->assertEquals('foo=bar', $uri->getQuery());
    }

    public function testWithQueryReturnsSameInstanceWhenQueryIsUnchangedEncoded()
    {
        $uri = new Uri('http://localhost?foo=a%20new%20text!');

        $new = $uri->withQuery('foo=a new%20text!');
        $this->assertSame($uri, $new);
        $this->assertEquals('foo=a%20new%20text!', $uri->getQuery());
    }

    public function testWithFragmentReturnsNewInstanceWhenFragmentIsChanged()
    {
        $uri = new Uri('http://localhost');

        $new = $uri->withFragment('section');
        $this->assertNotSame($uri, $new);
        $this->assertEquals('section', $new->getFragment());
        $this->assertEquals('', $uri->getFragment());
    }

    public function testWithFragmentReturnsNewInstanceWhenFragmentIsChangedEncoded()
    {
        $uri = new Uri('http://localhost');

        $new = $uri->withFragment('section new%20text!');
        $this->assertNotSame($uri, $new);
        $this->assertEquals('section%20new%20text!', $new->getFragment());
        $this->assertEquals('', $uri->getFragment());
    }

    public function testWithFragmentReturnsSameInstanceWhenFragmentIsUnchanged()
    {
        $uri = new Uri('http://localhost#section');

        $new = $uri->withFragment('section');
        $this->assertSame($uri, $new);
        $this->assertEquals('section', $uri->getFragment());
    }

    public function testWithFragmentReturnsSameInstanceWhenFragmentIsUnchangedEncoded()
    {
        $uri = new Uri('http://localhost#section%20new%20text!');

        $new = $uri->withFragment('section new%20text!');
        $this->assertSame($uri, $new);
        $this->assertEquals('section%20new%20text!', $uri->getFragment());
    }

    public static function provideResolveUris()
    {
        yield [
            'http://localhost/',
            '',
            'http://localhost/'
        ];
        yield [
            'http://localhost/',
            'http://example.com/',
            'http://example.com/'
        ];
        yield [
            'http://localhost/',
            'path',
            'http://localhost/path'
        ];
        yield [
            'http://localhost/',
            'path/',
            'http://localhost/path/'
        ];
        yield [
            'http://localhost/',
            'path//',
            'http://localhost/path/'
        ];
        yield [
            'http://localhost',
            'path',
            'http://localhost/path'
        ];
        yield [
            'http://localhost/a/b',
            '/path',
            'http://localhost/path'
        ];
        yield [
            'http://localhost/',
            '/a/b/c',
            'http://localhost/a/b/c'
        ];
        yield [
            'http://localhost/a/path',
            'b/c',
            'http://localhost/a/b/c'
        ];
        yield [
            'http://localhost/a/path',
            '/b/c',
            'http://localhost/b/c'
        ];
        yield [
            'http://localhost/a/path/',
            'b/c',
            'http://localhost/a/path/b/c'
        ];
        yield [
            'http://localhost/a/path/',
            '../b/c',
            'http://localhost/a/b/c'
        ];
        yield [
            'http://localhost',
            '../../../a/b',
            'http://localhost/a/b'
        ];
        yield [
            'http://localhost/path',
            '?query',
            'http://localhost/path?query'
        ];
        yield [
            'http://localhost/path',
            '#fragment',
            'http://localhost/path#fragment'
        ];
        yield [
            'http://localhost/path',
            'http://localhost',
            'http://localhost'
        ];
        yield [
            'http://localhost/path',
            'http://localhost/?query#fragment',
            'http://localhost/?query#fragment'
        ];
        yield [
            'http://localhost/path/?a#fragment',
            '?b',
            'http://localhost/path/?b'
        ];
        yield [
            'http://localhost/path',
            '//localhost',
            'http://localhost'
        ];
        yield [
            'http://localhost/path',
            '//localhost/a?query',
            'http://localhost/a?query'
        ];
        yield [
            'http://localhost/path',
            '//LOCALHOST',
            'http://localhost'
        ];
    }

    /**
     * @dataProvider provideResolveUris
     * @param string $base
     * @param string $rel
     * @param string $expected
     */
    public function testResolveReturnsResolvedUri($base, $rel, $expected)
    {
        $uri = Uri::resolve(new Uri($base), new Uri($rel));

        $this->assertEquals($expected, (string) $uri);
    }
}
