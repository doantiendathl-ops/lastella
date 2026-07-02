<?php

namespace App\Services\Posting;

interface PostingJob
{
    public function execute(PostingContext $context): PostingResult;

    public function rollback(PostingContext $context): void;

    public function isAlreadyPosted(PostingContext $context): bool;

    public function shouldProcess(PostingContext $context): bool;

    /** @return array<class-string<PostingJob>> */
    public function dependsOn(): array;
}
