<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;
use Symfony\Component\Process\Process;

class ExecutorPython
{
    public function __construct(private PcWorkerBridge $pcWorker) {}

    /** @return array{sucesso: bool, saida: string, erro: string} */
    public function executar(string $script, array $arguments = [], ?string $input = null): array
    {
        $timeout = (int) config('services.dxf.timeout_seconds');
        if (config('nesting.pc_worker_enabled')) {
            return $this->executarNoPc($script, $arguments, $input, $timeout);
        }
        if (function_exists('proc_open') && ! config('services.dxf.bridge_enabled')) {
            $process = new Process([
                config('services.dxf.python'),
                base_path('services/dxf-parser/'.$script),
                ...$arguments,
            ], base_path(), timeout: $timeout);
            $process->setEnv([
                'HOME' => storage_path('app/private'),
                'USERPROFILE' => storage_path('app/private'),
                'XDG_CONFIG_HOME' => storage_path('app/private/python-config'),
                'XDG_CACHE_HOME' => storage_path('app/private/python-cache'),
            ]);
            if ($input !== null) {
                $process->setInput($input);
            }
            $process->run();

            return ['sucesso' => $process->isSuccessful(), 'saida' => $process->getOutput(), 'erro' => $process->getErrorOutput()];
        }

        $url = config('services.dxf.bridge_url');
        $token = config('services.dxf.bridge_token');
        if (! $url || ! $token) {
            throw new RuntimeException('O serviço de processamento DXF não está configurado.');
        }
        $response = Http::withToken($token)->connectTimeout(5)->timeout($timeout + 10)
            ->post(rtrim($url, '/').'/python', [
                'script' => $script,
                'arguments' => $arguments,
                'input_base64' => base64_encode($input ?? ''),
            ]);
        if ($response->failed()) {
            throw new RuntimeException($response->json('erro') ?: 'O serviço de processamento DXF não respondeu.');
        }
        $saida = base64_decode((string) $response->json('output_base64'), true);
        if ($saida === false) {
            throw new RuntimeException('Resposta inválida do serviço de processamento DXF.');
        }

        return [
            'sucesso' => (bool) $response->json('ok'),
            'saida' => $saida,
            'erro' => (string) $response->json('stderr', ''),
        ];
    }

    private function executarNoPc(string $script, array $arguments, ?string $input, int $timeout): array
    {
        $allowed = ['extrair_geometria.py', 'aplicar_offset.py', 'gerar_cnc_saida.py',
            'gerar_dxf_saida.py', 'gerar_pdf_arranjo.py'];
        if (! in_array($script, $allowed, true)) {
            throw new RuntimeException('Operação Python não permitida.');
        }
        $path = $arguments[0] ?? null;
        $fileInput = null;
        $fileOutput = null;
        if ($script === 'extrair_geometria.py') {
            $this->assertPrivatePath($path);
            $fileInput = file_get_contents($path);
            if ($fileInput === false) {
                throw new RuntimeException('DXF não encontrado no armazenamento privado.');
            }
        } elseif (in_array($script, ['gerar_cnc_saida.py', 'gerar_dxf_saida.py'], true)
            && $path !== '--preview') {
            $this->assertPrivatePath($path);
            $fileOutput = $path;
        }
        $result = $this->pcWorker->solicitar('python', [
            'script' => $script,
            'preview' => $path === '--preview',
            'file_base64' => $fileInput === null ? null : base64_encode($fileInput),
            'save_file' => $fileOutput !== null,
            'input_base64' => base64_encode($input ?? ''),
        ], max(180, $timeout + 60));
        $saida = base64_decode((string) ($result['output_base64'] ?? ''), true);
        if ($saida === false) {
            throw new RuntimeException('Resposta inválida do processamento Python.');
        }
        if ($fileOutput !== null && ($result['ok'] ?? false)) {
            $artifact = base64_decode((string) ($result['artifact_base64'] ?? ''), true);
            $directory = dirname($fileOutput);
            if ($artifact === false || (! is_dir($directory) && ! mkdir($directory, 0770, true))
                || file_put_contents($fileOutput, $artifact) === false) {
                throw new RuntimeException('Não foi possível salvar o programa gerado.');
            }
        }

        return ['sucesso' => (bool) ($result['ok'] ?? false), 'saida' => $saida,
            'erro' => (string) ($result['stderr'] ?? '')];
    }

    private function assertPrivatePath(?string $path): void
    {
        $normalized = str_replace('\\', '/', $path ?? '');
        $private = rtrim(str_replace('\\', '/', storage_path('app/private')), '/').'/';
        if (! str_starts_with($normalized, $private) || str_contains($normalized, '/../')) {
            throw new RuntimeException('Caminho fora do armazenamento privado.');
        }
    }
}
