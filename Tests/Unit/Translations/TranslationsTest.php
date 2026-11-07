<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Tests\Unit\Translations;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every translation parses the way Mautic parses it and matches en_US key for key, with the
 * same placeholders and HTML tags.
 */
class TranslationsTest extends TestCase
{
    private const DIR = __DIR__.'/../../../Translations';

    /**
     * @return iterable<string, array{string}>
     */
    public static function localeProvider(): iterable
    {
        foreach (glob(self::DIR.'/*', GLOB_ONLYDIR) ?: [] as $dir) {
            if ('en_US' !== basename($dir)) {
                yield basename($dir) => [basename($dir)];
            }
        }
    }

    #[DataProvider('localeProvider')]
    public function testMatchesEnglish(string $locale): void
    {
        $english    = self::load('en_US');
        $translated = self::load($locale);

        $this->assertSame([], array_values(array_diff(array_keys($english), array_keys($translated))), "Missing in $locale");
        $this->assertSame([], array_values(array_diff(array_keys($translated), array_keys($english))), "Not in en_US");

        foreach ($english as $key => $text) {
            $this->assertSame(self::markers($text), self::markers($translated[$key]), "Placeholders or tags differ: $key");
        }
    }

    /**
     * @return array<string, string>
     */
    private static function load(string $locale): array
    {
        // As Mautic's TranslationLoader does.
        $messages = parse_ini_string((string) file_get_contents(self::DIR.'/'.$locale.'/messages.ini'), true);
        self::assertIsArray($messages, "$locale/messages.ini does not parse");

        return $messages;
    }

    /**
     * @return list<string>
     */
    private static function markers(string $text): array
    {
        preg_match_all('/%[a-z_]+%|<\/?[a-z]+/', $text, $matches);
        sort($matches[0]);

        return $matches[0];
    }
}
