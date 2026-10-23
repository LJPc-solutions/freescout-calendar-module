<?php

namespace Modules\LJPcCalendarModule\CalendarProviders;

use InvalidArgumentException;

/** The plain text of an HTML event description (Google's descriptions, Outlook's bodies), as FreeScout stores it. */
final class HtmlText
{
    /**
     * A link with its target in either quote and its label up to its </a>. Every part matches in one pass (quote-bounded
     * targets, a label loop that never backtracks), so a long description with a link that is never closed costs time
     * in proportion to its length.
     */
    private const LINK = '~<a\b[^>]*?\bhref\s*=\s*(?:"([^"]*)"|\'([^\']*)\')[^>]*>([^<]*+(?:<(?!/a>)[^<]*+)*+)</a>~i';

    /**
     * Line breaks and link targets stay; tags go, entities become characters, spaces at line ends go. Throws
     * InvalidArgumentException when PCRE gives up on the text: the event is then skipped and its stored copy kept, never
     * replaced by an empty description.
     */
    public static function toPlain(string $html): string
    {
        $text = self::checked(preg_replace_callback(self::LINK, function (array $match) {
            $label = trim(strip_tags($match[3]));
            $url = html_entity_decode($match[1] !== '' ? $match[1] : $match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');

            return $label === '' || $label === $url ? $url : $label . ' (' . $url . ')';
        }, $html));
        $text = self::checked(preg_replace(['~<br\s*/?>~i', '~</(p|div|li|h[1-6])>~i', '~<li\b[^>]*>~i'], ["\n", "\n", '- '], $text));

        // A non-breaking space (&nbsp;) becomes a plain one, and spaces at line ends go, so the readable block's marker
        // line is found again.
        $text = str_replace("\u{00A0}", ' ', html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        return trim(implode("\n", array_map(function (string $line) {
            return rtrim($line, " \t");
        }, explode("\n", $text))));
    }

    private static function checked(?string $text): string
    {
        if ($text === null) {
            throw new InvalidArgumentException('The HTML description could not be read: ' . preg_last_error_msg());
        }

        return $text;
    }
}
