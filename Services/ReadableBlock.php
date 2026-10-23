<?php

namespace Modules\LJPcCalendarModule\Services;

use Modules\LJPcCalendarModule\Entities\Calendar;
use Modules\LJPcCalendarModule\Entities\CalendarEvent;
use Modules\LJPcCalendarModule\Entities\CalendarInvite;
use Sabre\VObject\Component\VEvent;

/**
 * The "[FreeScout]" block at the end of a description, which makes custom fields and the ticket link visible
 * in every calendar client. FreeScout's database stays the source; the block is rewritten on every write.
 */
final class ReadableBlock
{
    public const MARKER = '[FreeScout]';

    public static function strip(string $description): string
    {
        $lines = preg_split("/\r\n|\n/", $description);
        foreach ($lines as $index => $line) {
            if (trim($line) === self::MARKER) {
                return rtrim(implode("\n", array_slice($lines, 0, $index)));
            }
        }

        return $description;
    }

    public static function lines(CalendarEvent $event): array
    {
        $fields = [];
        foreach ($event->calendar->fieldDefinitions() as $field) {
            $fields[(string) $field['id']] = $field;
        }
        $lines = [];
        foreach ((array) $event->field_values as $id => $value) {
            if (isset($fields[(string) $id])) {
                $lines[] = $fields[(string) $id]['name'] . ': ' . FieldValues::display($fields[(string) $id], $value);
            }
        }
        // Google and Microsoft get an invite without its organizer (decision 2026-10-19): the block names who sent it.
        if (in_array($event->calendar->provider, [Calendar::PROVIDER_GOOGLE, Calendar::PROVIDER_MICROSOFT], true)) {
            $invite = CalendarInvite::where('uid_hash', CalendarEvent::hashUid($event->uid))->whereNotNull('organizer_email')->orderBy('id', 'desc')->first();
            if ($invite !== null) {
                $lines[] = __('Organizer: :organizer', ['organizer' => $invite->organizer_name ? $invite->organizer_name . ' <' . $invite->organizer_email . '>' : $invite->organizer_email]);
            }
            // Nor do they get the people we invite (InviteSender): the block lists them with their answers.
            if ($event->attendees) {
                $lines[] = __('Attendees: :attendees', ['attendees' => implode(', ', array_map(function (array $attendee) {
                    $answers = ['ACCEPTED' => __('accepted'), 'TENTATIVE' => __('maybe'), 'DECLINED' => __('declined')];

                    return ($attendee['name'] !== '' ? $attendee['name'] . ' <' . $attendee['email'] . '>' : $attendee['email'])
                        . ' (' . ($answers[$attendee['partstat']] ?? __('no answer yet')) . ')';
                }, $event->attendees))]);
            }
        }
        if ($event->conversation_id && ($conversation = $event->conversation)) {
            $lines[] = __('Ticket #:number: :url', ['number' => $conversation->number, 'url' => $conversation->url()]);
        }

        return $lines;
    }

    /** Puts the block at the end of this VEVENT's DESCRIPTION, replacing an old one: the export and every write to a provider. */
    public static function addTo(VEvent $vevent, CalendarEvent $event): void
    {
        $description = self::append((string) ($vevent->DESCRIPTION ?? ''), $event);
        unset($vevent->DESCRIPTION);
        if ($description !== '') {
            $vevent->DESCRIPTION = $description;
        }
    }

    public static function append(string $description, CalendarEvent $event): string
    {
        $clean = rtrim(self::strip($description));
        $lines = self::lines($event);
        if (!$lines) {
            return $clean;
        }

        return ltrim($clean . "\n\n" . self::MARKER . "\n" . implode("\n", $lines));
    }
}
