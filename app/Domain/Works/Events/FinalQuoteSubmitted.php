<?php

namespace App\Domain\Works\Events;

use App\Infrastructure\Persistence\Eloquent\WorkModel;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class FinalQuoteSubmitted
{
    use Dispatchable, SerializesModels;

    public function __construct(public WorkModel $work) {}
}
