<?php

namespace App\Services\Posting;

use App\Models\FolioEntry;

final class PostingResult
{
    private function __construct(
        public readonly bool $success,
        public readonly bool $alreadyPosted,
        public readonly string $message,
        public readonly ?FolioEntry $entry,
    ) {}

    public static function posted(FolioEntry $entry): self
    {
        return new self(success: true, alreadyPosted: false, message: '', entry: $entry);
    }

    public static function alreadyPosted(): self
    {
        return new self(success: true, alreadyPosted: true, message: 'Already posted', entry: null);
    }

    public static function skipped(string $reason = ''): self
    {
        return new self(success: true, alreadyPosted: false, message: $reason, entry: null);
    }

    public static function failed(string $reason = ''): self
    {
        return new self(success: false, alreadyPosted: false, message: $reason, entry: null);
    }
}
