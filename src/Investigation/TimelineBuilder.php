<?php

declare(strict_types=1);

namespace Beacon\Investigation;

use Beacon\Data\InvestigationEvidenceItem;
use Beacon\Data\InvestigationTimelineEvent;

/**
 * Builds a chronological timeline from classified evidence.
 */
final class TimelineBuilder
{
    /**
     * @param list<InvestigationEvidenceItem> $items
     *
     * @return array{events: list<InvestigationTimelineEvent>, truncated: bool}
     */
    public function build(array $items, int $limit): array
    {
        $events = [];
        $seen = [];

        foreach ($items as $item) {
            if (isset($seen[$item->evidenceId])) {
                continue;
            }

            $seen[$item->evidenceId] = true;
            $events[] = new InvestigationTimelineEvent(
                eventId: 'evt_' . $item->evidenceId,
                eventType: $item->type,
                timestamp: $item->timestamp,
                source: $item->source,
                summary: $item->summary,
                correlation: $item->correlation,
                identifiers: $item->identifiers,
            );
        }

        usort(
            $events,
            static function (InvestigationTimelineEvent $a, InvestigationTimelineEvent $b): int {
                if ($a->timestamp === null && $b->timestamp === null) {
                    return strcmp($a->eventId, $b->eventId);
                }

                if ($a->timestamp === null) {
                    return 1;
                }

                if ($b->timestamp === null) {
                    return -1;
                }

                return $a->timestamp <=> $b->timestamp ?: strcmp($a->eventId, $b->eventId);
            },
        );

        $truncated = count($events) > $limit;

        return [
            'events' => array_slice($events, 0, max(0, $limit)),
            'truncated' => $truncated,
        ];
    }
}
