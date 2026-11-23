<?php declare(strict_types=1);

namespace BotChallengeTest\Mvc;

use Omeka\Settings\Settings;
use PHPUnit\Framework\TestCase;

/**
 * @covers \BotChallenge\Mvc\MvcListeners::pathIsExcepted
 */
class PathIsExceptedTest extends TestCase
{
    /**
     * @var TestableMvcListeners
     */
    protected $listener;

    public function setUp(): void
    {
        $settings = $this->createMock(Settings::class);
        $this->listener = new TestableMvcListeners($settings);
    }

    /**
     * @dataProvider pathProvider
     */
    public function testPathIsExcepted(string $path, bool $expected): void
    {
        $exceptionPaths = ['/api', '/sso', '/access/authorize'];
        $this->assertSame(
            $expected,
            $this->listener->publicPathIsExcepted($path, $exceptionPaths),
            sprintf('pathIsExcepted("%s") should return %s', $path, $expected ? 'true' : 'false')
        );
    }

    public function pathProvider(): array
    {
        return [
            'exact match' => ['/sso', true],
            'sub-path' => ['/sso/metadata', true],
            'deep sub-path' => ['/access/authorize/42/token', true],
            'exact multi-segment' => ['/access/authorize', true],
            'prefix without boundary is not matched' => ['/ssoxxx', false],
            'sibling segment is not matched' => ['/access/authorized', false],
            'unrelated path' => ['/page/contact', false],
            'root' => ['/', false],
        ];
    }

    /**
     * @dataProvider suffixProvider
     */
    public function testSuffixPatternMatchesLastSegment(string $path, bool $expected): void
    {
        $exceptionPaths = ['*/suggest', '*/atom', '*/rss'];
        $this->assertSame(
            $expected,
            $this->listener->publicPathIsExcepted($path, $exceptionPaths),
            sprintf('suffix pathIsExcepted("%s") should return %s', $path, $expected ? 'true' : 'false')
        );
    }

    public function suffixProvider(): array
    {
        return [
            'search suggest with slug' => ['/s/mysite/recherche/suggest', true],
            'search suggest other slug' => ['/s/mysite/search/suggest', true],
            'search atom with slug' => ['/s/mysite/recherche/atom', true],
            'search rss with slug' => ['/s/mysite/recherche/rss', true],
            'top-level suggest' => ['/suggest', true],
            'segment boundary respected' => ['/foo/diatom', false],
            'suggest as prefix of another segment' => ['/s/site/search/suggestions', false],
            'unrelated' => ['/s/mysite/page/contact', false],
        ];
    }

    /**
     * @dataProvider wildcardProvider
     */
    public function testInnerStarMatchesAnyCharacters(string $path, bool $expected): void
    {
        $exceptionPaths = ['*/sitemap-*.xml', '*/sitemap.xml', '*/sitemapindex.xml'];
        $this->assertSame(
            $expected,
            $this->listener->publicPathIsExcepted($path, $exceptionPaths),
            sprintf('wildcard pathIsExcepted("%s") should return %s', $path, $expected ? 'true' : 'false')
        );
    }

    public function wildcardProvider(): array
    {
        return [
            'site sitemap' => ['/s/mysite/sitemap.xml', true],
            'site sitemap page' => ['/s/mysite/sitemap-1.xml', true],
            'site sitemap high page' => ['/s/mysite/sitemap-14.xml', true],
            'site sitemap index' => ['/s/mysite/sitemapindex.xml', true],
            'root sitemap' => ['/sitemap.xml', true],
            'root sitemap page' => ['/sitemap-2.xml', true],
            'other xml file' => ['/s/mysite/page/sitemap-help', false],
            'page named like a sitemap' => ['/s/mysite/page/sitemaps', false],
        ];
    }

    public function testTrailingSlashInExceptionIsNormalized(): void
    {
        $this->assertTrue($this->listener->publicPathIsExcepted('/oai', ['/oai/']));
        $this->assertTrue($this->listener->publicPathIsExcepted('/oai/request', ['/oai/']));
    }

    public function testEmptyExceptionPathNeverMatches(): void
    {
        $this->assertFalse($this->listener->publicPathIsExcepted('/anything', ['', '/']));
    }
}
