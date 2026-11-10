<?php

namespace App\Console\Commands;

use App\Services\ArranjoPreview;
use Illuminate\Console\Command;

class LimparPreviewsCommand extends Command
{
    protected $signature = 'previews:limpar';

    protected $description = 'Remove arquivos temporários de previews expirados';

    public function handle(ArranjoPreview $previews): int
    {
        $count = $previews->limparExpirados();
        $this->info("{$count} preview(s) temporário(s) removido(s).");

        return self::SUCCESS;
    }
}
