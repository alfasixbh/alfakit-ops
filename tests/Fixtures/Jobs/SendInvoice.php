<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitOps\Tests\Fixtures\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendInvoice implements ShouldQueue
{
    use Queueable;

    /** @var array<string, string> */
    public array $customer = ['name' => 'Ada', 'card_holder' => 'A. Lovelace', 'billing_password' => 'hunter2'];

    public function __construct(public int $invoice = 42, protected string $apiToken = 'tok_live_123', private string $password = 's3cr3t') {}

    public function handle(): void {}
}
