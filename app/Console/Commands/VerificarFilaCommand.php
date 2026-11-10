<?php

namespace App\Console\Commands;

use App\Jobs\VerificarFila;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

class VerificarFilaCommand extends Command
{
    protected $signature = 'app:verificar-fila';

    protected $description = 'Valida a fila de banco de dados com um job real em uma fila isolada';

    public function handle(): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('Disponivel apenas em ambiente local/testing.');

            return self::FAILURE;
        }

        $token = (string) Str::uuid();
        $queue = 'verificacao-'.$token;
        VerificarFila::dispatch($token)->onConnection('database')->onQueue($queue);

        $worker = new Process([
            PHP_BINARY, base_path('artisan'), 'queue:work', 'database',
            '--queue='.$queue, '--once', '--tries=1', '--timeout=20',
        ], base_path(), timeout: 30);
        $worker->run();
        $this->output->write($worker->getOutput());

        if (! $worker->isSuccessful() || Cache::pull('queue-check:'.$token) !== 'ok') {
            $this->error('Falha ao processar o job: '.$worker->getErrorOutput());

            return self::FAILURE;
        }

        $this->info('Fila database validada: job enfileirado e processado com sucesso.');

        return self::SUCCESS;
    }
}
