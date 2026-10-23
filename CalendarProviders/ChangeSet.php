<?php

namespace Modules\LJPcCalendarModule\CalendarProviders;

/** The result of one pull round. */
final class ChangeSet
{
    /** @var RemoteEvent[] */
    public array $upserts;

    /** @var string[] remote ids of events the provider deleted */
    public array $deletes;

    /** The provider's next sync state (page token, sync token, etag); saved after the round is applied. */
    public array $state;

    /** False while the provider has more pages; the engine pulls again while its budget lasts, or in the next run. */
    public bool $complete;

    /** Part of a listing of every event: once the listing completes, events it did not mention are deleted. */
    public bool $fullListing;

    /** @var string[] remote ids seen unchanged during a full listing */
    public array $unchanged;

    /**
     * @param RemoteEvent[] $upserts
     * @param string[]      $deletes   remote ids
     * @param string[]      $unchanged remote ids seen unchanged during a full listing
     */
    public function __construct(array $upserts, array $deletes, array $state, bool $complete, bool $fullListing, array $unchanged = [])
    {
        $this->upserts = $upserts;
        $this->deletes = $deletes;
        $this->state = $state;
        $this->complete = $complete;
        $this->fullListing = $fullListing;
        $this->unchanged = $unchanged;
    }
}
