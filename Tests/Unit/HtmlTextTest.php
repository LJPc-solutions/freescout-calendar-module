<?php

namespace Modules\LJPcCalendarModule\Tests\Unit;

use Modules\LJPcCalendarModule\CalendarProviders\HtmlText;
use Modules\LJPcCalendarModule\Tests\TestCase;

class HtmlTextTest extends TestCase
{
    public function test_links_keep_their_targets_in_either_quote(): void
    {
        $html = '<p>See <a href="https://example.com/a?x=1&amp;y=2">the notes</a> and <a class="l" href=\'https://example.com/b\'>https://example.com/b</a></p>';

        $this->assertSame('See the notes (https://example.com/a?x=1&y=2) and https://example.com/b', HtmlText::toPlain($html));
    }

    public function test_a_long_description_with_an_unclosed_link_keeps_its_text(): void
    {
        // Thousands of quotes after a link without its </a>: a backtracking pattern gives up, and the text was lost.
        $html = '<p>Agenda for the <b>review</b></p><a href="https://example.com/notes">' . str_repeat('<span class="item">Point</span> <i title="x">one</i><br>', 160);
        $this->assertGreaterThan(8 * 1024, strlen($html));

        $text = HtmlText::toPlain($html);

        $this->assertStringStartsWith("Agenda for the review\nPoint one\nPoint one\n", $text);
        $this->assertSame(160, substr_count($text, 'Point one'));
    }

    public function test_text_pcre_gives_up_on_is_refused_instead_of_emptied(): void
    {
        $limit = ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', '1');
        try {
            HtmlText::toPlain('<p>Hi <a href="https://example.com">there</a></p>');
            $this->fail('The text was converted although PCRE gave up on it.');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('The HTML description could not be read: Backtrack limit exhausted', $e->getMessage());
        } finally {
            ini_set('pcre.backtrack_limit', $limit);
        }
    }

    public function test_spaces_at_line_ends_go_however_many_there_are(): void
    {
        $this->assertSame("First\n" . str_repeat(' ', 20000) . "x\nLast", HtmlText::toPlain('First   <br>' . str_repeat(' ', 20000) . 'x<br>Last' . "\t "));
    }
}
