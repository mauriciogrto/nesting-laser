<?php

namespace Tests\Feature;

use App\Services\ExecutorPython;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class ExecutorPythonTest extends TestCase
{
    public function test_bridge_sends_authenticated_input_and_decodes_binary_output(): void
    {
        config()->set('services.dxf.bridge_enabled', true);
        config()->set('services.dxf.bridge_url', 'http://127.0.0.1:3001');
        config()->set('services.dxf.bridge_token', 'test-token');
        Http::preventStrayRequests();
        Http::fake(['http://127.0.0.1:3001/python' => Http::response([
            'ok' => true, 'output_base64' => base64_encode("%PDF-1.7\0binary"), 'stderr' => '',
        ])]);

        $result = app(ExecutorPython::class)->executar('gerar_pdf_arranjo.py', [], '{"pedido":1}');

        $this->assertTrue($result['sucesso']);
        $this->assertSame("%PDF-1.7\0binary", $result['saida']);
        Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer test-token')
            && $request['script'] === 'gerar_pdf_arranjo.py'
            && base64_decode($request['input_base64']) === '{"pedido":1}');
    }

    public function test_bridge_rejects_invalid_binary_response(): void
    {
        config()->set('services.dxf.bridge_enabled', true);
        config()->set('services.dxf.bridge_url', 'http://127.0.0.1:3001');
        config()->set('services.dxf.bridge_token', 'test-token');
        Http::preventStrayRequests();
        Http::fake(['http://127.0.0.1:3001/python' => Http::response(['ok' => true, 'output_base64' => '?'])]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Resposta inválida');
        app(ExecutorPython::class)->executar('gerar_pdf_arranjo.py');
    }
}
