<?php

declare(strict_types=1);

namespace Petitstats\Tests\Tracking;

use Petitstats\Tracking\BotScore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BotScoreTest extends TestCase
{
    /** A French visitor on an iPhone, reading a French page and scrolling. */
    private const FRENCH_IPHONE_VISIT = ['path' => '/blog/lettre', 'language' => 'fr-FR', 'page_lang' => 'fr', '_ml_confirm' => 'interaction', 'screen' => '393×852'];

    public function testARealVisitWithInteractionsIsNeverSuspicious(): void
    {
        $this->assertSame([], BotScore::signals(self::FRENCH_IPHONE_VISIT));
        $this->assertSame(0, BotScore::score(self::FRENCH_IPHONE_VISIT));
        $this->assertNull(BotScore::reason(self::FRENCH_IPHONE_VISIT));
    }

    /** @param array<string, string> $data */
    #[DataProvider('people')]
    public function testPeopleShowingSomeSignalsAreKept(array $data): void
    {
        $this->assertNull(BotScore::reason($data), implode('+', BotScore::signals($data)));
    }

    /** @return iterable<string, array{array<string, string>}> */
    public static function people(): iterable
    {
        yield 'an English browser on a French page, alone' => [['language' => 'en-US', 'page_lang' => 'fr', '_ml_confirm' => 'interaction']];
        yield 'an English reader who does not move for 3 seconds' => [['language' => 'en-US', 'page_lang' => 'fr', '_ml_confirm' => 'visible']];
        yield 'a Linux computer without a graphics driver, interacting' => [['language' => 'en-US', 'page_lang' => 'fr', '_ml_confirm' => 'interaction', '_ml_signals' => 'software_gl']];
        yield 'a regional language variant' => [['language' => 'fr-CA', 'page_lang' => 'fr-FR', '_ml_confirm' => 'visible', '_ml_signals' => 'software_gl']];
        yield 'a page without a language' => [['language' => 'en-US', '_ml_confirm' => 'visible', '_ml_signals' => 'software_gl']];
        yield 'an older tracker sending nothing new' => [['language' => 'en-US']];
    }

    public function testTheScraperOfNovember25IsRejected(): void
    {
        $scraper = ['language' => 'en-US', 'page_lang' => 'fr', '_ml_confirm' => 'visible', '_ml_signals' => 'software_gl'];

        $this->assertSame(['software_gl', 'passive', 'language_mismatch'], BotScore::signals($scraper));
        $this->assertSame('suspicious:software_gl+passive+language_mismatch', BotScore::reason($scraper));
    }

    public function testMissingPluginsAndPassiveVisitAddUp(): void
    {
        $this->assertSame('suspicious:no_plugins+passive', BotScore::reason(['_ml_confirm' => 'visible', '_ml_signals' => 'no_plugins']));
    }

    public function testUnknownSignalsAreIgnored(): void
    {
        $this->assertSame(['passive'], BotScore::signals(['_ml_confirm' => 'visible', '_ml_signals' => 'evil<script>,software_gl_x']));
        $this->assertSame([], BotScore::signals(['_ml_signals' => ['software_gl']]));
    }
}
