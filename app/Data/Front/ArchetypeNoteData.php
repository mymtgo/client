<?php

namespace App\Data\Front;

use App\Models\DeckArchetypeNote;
use Carbon\Carbon;
use Spatie\LaravelData\Data;

/** @typescript  */
class ArchetypeNoteData extends Data
{
    public function __construct(
        public int $id,
        public string $body,
        public string $deckName,
        public Carbon $createdAt,
    ) {}

    public static function fromModel(DeckArchetypeNote $note): self
    {
        return new self(
            id: $note->id,
            body: $note->body,
            deckName: $note->deck->name,
            createdAt: $note->created_at,
        );
    }
}
