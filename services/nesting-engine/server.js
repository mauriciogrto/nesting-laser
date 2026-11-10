import 'dotenv/config';
import express from 'express';
import {nest} from './engine.js';
import {evaluateLayout} from './layout.js';
import {improveNesting, shouldImprove} from './quality.js';
import {timingSafeEqual} from 'node:crypto';
import {runPython} from './python.js';

const app = express();
app.disable('x-powered-by');
app.use(express.json({limit: '10mb'}));

app.use((request, response, next) => {
  const token = process.env.NESTING_ENGINE_TOKEN;
  if (!token) return next();
  const received = request.get('authorization')?.replace(/^Bearer /i, '') || '';
  const expectedBytes = Buffer.from(token);
  const receivedBytes = Buffer.from(received);
  if (receivedBytes.length !== expectedBytes.length || !timingSafeEqual(receivedBytes, expectedBytes)) {
    return response.status(401).json({erro: 'Acesso não autorizado ao motor.'});
  }
  return next();
});

app.get('/health', (_request, response) => response.json({status: 'ok'}));
app.post('/python', async (request, response) => {
  try {
    response.json(await runPython(request.body));
  } catch (error) {
    response.status(422).json({erro: error.message});
  }
});
app.post('/nesting', async (request, response) => {
  try {
    const detailed = shouldImprove(request.body);
    const result = nest(request.body, {
      timeoutMs: detailed ? 4000 : Number(process.env.NESTING_TIMEOUT_MS || 30000),
      maxGenerations: Number(process.env.NESTING_MAX_GENERATIONS || 20),
      fastOnly: detailed,
    });
    response.json(detailed ? await improveNesting(request.body, result) : result);
  } catch (error) {
    response.status(422).json({erro: error.message});
  }
});
app.post('/avaliar', (request, response) => {
  try {
    response.json(evaluateLayout(request.body));
  } catch (error) {
    response.status(422).json({erro: error.message});
  }
});

if (process.env.NODE_ENV !== 'test') {
  app.listen(Number(process.env.PORT || 3001), process.env.HOST || '127.0.0.1', () => {
    console.log(`Nesting engine em http://${process.env.HOST || '127.0.0.1'}:${process.env.PORT || 3001}`);
  });
}

export default app;
