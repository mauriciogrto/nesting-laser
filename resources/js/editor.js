import Konva from 'konva';
import clipping from 'polygon-clipping';

const config = document.getElementById('editor-config') || document.getElementById('preview-config');
if (config) bootEditor().catch(error => message(error.message));

function message(value) { window.dispatchEvent(new CustomEvent('editor-mensagem', {detail: value})); }
function event(name, value) { window.dispatchEvent(new CustomEvent(name, {detail: value})); }
function closeRing(points) { return [...points, points[0]]; }
function multiArea(polygons) {
    return polygons.reduce((sum, polygon) => sum + polygon.reduce((area, ring, index) => {
        const value = Math.abs(ring.reduce((total, point, i) => {
            const next = ring[(i + 1) % ring.length];
            return total + point[0] * next[1] - next[0] * point[1];
        }, 0) / 2);
        return area + (index ? -value : value);
    }, 0), 0);
}
function rotatedRings(contours, degrees) {
    const angle = degrees * Math.PI / 180, cos = Math.cos(angle), sin = Math.sin(angle);
    const rotated = contours.map(ring => ring.map(([x, y]) => [x * cos - y * sin, x * sin + y * cos]));
    const minX = Math.min(...rotated[0].map(point => point[0]));
    const minY = Math.min(...rotated[0].map(point => point[1]));
    return {rings: rotated.map(ring => ring.map(([x, y]) => [x - minX, y - minY])), minX, minY};
}
function worldRings(item, x = item.x, y = item.y, rotation = item.rotacao_graus) {
    const {rings} = rotatedRings(item.contornos, rotation);
    return rings.map(ring => ring.map(([px, py]) => [px + x, py + y]));
}
function polygonGeometry(rings) { return rings.map(closeRing); }
function contained(rings, sheet) {
    try { return multiArea(clipping.difference(polygonGeometry(rings), polygonGeometry([sheet]))) <= 0.0001; }
    catch { return false; }
}
function collision(first, second) {
    try { return multiArea(clipping.intersection(polygonGeometry(first), polygonGeometry(second))) > 0.0001; }
    catch { return true; }
}
function pointSegmentDistanceSquared(point, start, end) {
    const dx = end[0] - start[0], dy = end[1] - start[1];
    const length = dx * dx + dy * dy;
    const t = length ? Math.max(0, Math.min(1, ((point[0] - start[0]) * dx + (point[1] - start[1]) * dy) / length)) : 0;
    return (point[0] - start[0] - t * dx) ** 2 + (point[1] - start[1] - t * dy) ** 2;
}
function contoursTooClose(first, second, clearance) {
    if (collision(first, second)) return true;
    if (clearance <= 0) return false;
    const limit = Math.max(0, clearance - 0.001) ** 2;
    for (const ringA of first) for (const ringB of second) for (let i = 0; i < ringA.length; i++) {
        const a = ringA[i], nextA = ringA[(i + 1) % ringA.length];
        for (let j = 0; j < ringB.length; j++) {
            const b = ringB[j], nextB = ringB[(j + 1) % ringB.length];
            if (Math.max(a[0], nextA[0]) + clearance < Math.min(b[0], nextB[0]) ||
                Math.max(b[0], nextB[0]) + clearance < Math.min(a[0], nextA[0]) ||
                Math.max(a[1], nextA[1]) + clearance < Math.min(b[1], nextB[1]) ||
                Math.max(b[1], nextB[1]) + clearance < Math.min(a[1], nextA[1])) continue;
            if (pointSegmentDistanceSquared(a, b, nextB) < limit || pointSegmentDistanceSquared(nextA, b, nextB) < limit ||
                pointSegmentDistanceSquared(b, a, nextA) < limit || pointSegmentDistanceSquared(nextB, a, nextA) < limit) return true;
        }
    }
    return false;
}
function bounds(ring) {
    return {minX: Math.min(...ring.map(point => point[0])), maxX: Math.max(...ring.map(point => point[0])),
        minY: Math.min(...ring.map(point => point[1])), maxY: Math.max(...ring.map(point => point[1]))};
}
function positions(items) {
    return items.map(({data, group}) => {
        const local = rotatedRings(data.contornos, group.rotation());
        return {
            instancia: data.instancia, id: data.id,
            x: Math.round((group.x() + local.minX) * 1000) / 1000,
            y: Math.round((group.y() + local.minY) * 1000) / 1000,
            rotacao_graus: ((group.rotation() % 360) + 360) % 360,
        };
    });
}
async function post(url, body) {
    const response = await fetch(url, {
        method: 'POST',
        headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content},
        body: JSON.stringify(body),
    });
    const data = await response.json();
    if (!response.ok) throw new Error(Object.values(data.errors ?? {}).flat().join(' ') || data.message || 'Falha ao salvar o arranjo.');
    return data;
}

async function bootEditor() {
    const preview = config.id === 'preview-config';
    const readonly = false;
    const response = await fetch(config.dataset.url, {headers: {'Accept': 'application/json'}});
    if (!response.ok) {
        const problem = await response.json().catch(() => ({}));
        throw new Error(problem.message || 'Não foi possível carregar o arranjo.');
    }
    const model = await response.json();
    if (model.erro_cnc) message(model.erro_cnc);
    const margin = Number(model.margem_borda_mm || 0);
    const spacing = Number(model.distancia_pecas_mm || 0);
    const container = document.getElementById('nesting-canvas');
    if (!model.pecas_posicionadas.length) {
        const empty = document.createElement('p');
        empty.className = 'pointer-events-none absolute inset-0 z-10 flex items-center justify-center p-8 text-center font-medium text-amber-900';
        empty.textContent = 'Nenhuma peça coube nesta chapa. Escolha outra chapa ou cadastre mais estoque.';
        container.append(empty);
    }
    const sheet = model.chapa.contorno ?? [[0, 0], [model.chapa.largura_mm, 0], [model.chapa.largura_mm, model.chapa.altura_mm], [0, model.chapa.altura_mm]];
    const stage = new Konva.Stage({container, width: container.clientWidth, height: container.clientHeight});
    const layer = new Konva.Layer(); stage.add(layer);
    if (margin > 0 || spacing > 0) {
        const badge = document.createElement('span');
        badge.style.cssText = 'position:absolute;left:8px;top:8px;z-index:10;pointer-events:none;background:#fffbeb;color:#78350f;padding:4px 8px;border-radius:4px;font-size:12px;font-weight:600;box-shadow:0 1px 3px #0002;max-width:calc(100% - 16px)';
        badge.textContent = `Kerf ${Number(model.kerf_mm || 0)} mm · folga mínima entre cortes ${spacing} mm · margem da chapa ${margin} mm`;
        container.append(badge);
    }
    const items = [];
    const canvasMargin = 24;
    let fitScale = 1, viewZoom = 1, zoomLabel = null;
    function fitCanvas() {
        stage.size({width: container.clientWidth, height: container.clientHeight});
        fitScale = Math.min((stage.width() - canvasMargin * 2) / model.chapa.largura_mm, (stage.height() - canvasMargin * 2) / model.chapa.altura_mm);
        viewZoom = 1;
        if (zoomLabel) zoomLabel.textContent = '100%';
        layer.position({x: (stage.width() - model.chapa.largura_mm * fitScale) / 2, y: canvasMargin + model.chapa.altura_mm * fitScale});
        layer.scale({x: fitScale, y: -fitScale});
        updateLabels();
        layer.batchDraw();
    }
    fitCanvas();
    const observer = new ResizeObserver(fitCanvas); observer.observe(container);
    const zoomControls = document.createElement('div');
    zoomControls.style.cssText = 'position:absolute;right:8px;bottom:8px;z-index:10;display:flex;align-items:center;gap:4px;background:#fff;padding:4px;border:1px solid #cbd5e1;border-radius:6px;box-shadow:0 1px 4px #0003';
    zoomLabel = document.createElement('span');
    zoomLabel.style.cssText = 'min-width:42px;text-align:center;font-size:12px;color:#334155';
    function selectedAnchor() {
        const selected = transformer?.nodes()[0];
        const item = items.find(candidate => candidate.group === selected);
        if (!item) return {x: stage.width() / 2, y: stage.height() / 2};
        const box = bounds(getWorld(item)[0]);
        return layer.getAbsoluteTransform().point({x: (box.minX + box.maxX) / 2, y: (box.minY + box.maxY) / 2});
    }
    function zoomTo(value, anchor = selectedAnchor()) {
        const next = Math.max(1, Math.min(16, value));
        if (next === viewZoom) return;
        const world = layer.getAbsoluteTransform().copy().invert().point(anchor);
        viewZoom = next;
        const scale = fitScale * viewZoom;
        layer.scale({x: scale, y: -scale});
        layer.position({x: anchor.x - world.x * scale, y: anchor.y + world.y * scale});
        zoomLabel.textContent = `${Math.round(viewZoom * 100)}%`;
        updateLabels();
        layer.batchDraw();
    }
    for (const [label, action, title] of [
        ['−', () => zoomTo(viewZoom / 1.7), 'Diminuir zoom'],
        ['+', () => zoomTo(viewZoom * 1.7), 'Aumentar zoom'],
        ['Ajustar', fitCanvas, 'Mostrar chapa inteira'],
    ]) {
        if (label === 'Ajustar') zoomControls.append(zoomLabel);
        const button = document.createElement('button');
        button.type = 'button'; button.textContent = label; button.title = title;
        button.setAttribute('aria-label', title);
        button.style.cssText = 'border:1px solid #cbd5e1;border-radius:4px;padding:2px 7px;background:#fff;color:#1e293b;font-size:13px;font-weight:600';
        button.addEventListener('click', action);
        zoomControls.append(button);
    }
    zoomLabel.textContent = '100%';
    container.append(zoomControls);
    stage.on('wheel', e => {
        e.evt.preventDefault();
        zoomTo(viewZoom * (e.evt.deltaY < 0 ? 1.4 : 1 / 1.4), stage.getPointerPosition());
    });
    let panPoint = null;
    stage.on('mousedown', e => {
        if (e.evt.altKey && viewZoom > 1) {
            panPoint = stage.getPointerPosition();
            for (const item of items) item.group.draggable(false);
            e.evt.preventDefault();
        }
    });
    stage.on('mousemove', () => {
        if (!panPoint) return;
        const point = stage.getPointerPosition();
        layer.move({x: point.x - panPoint.x, y: point.y - panPoint.y});
        panPoint = point;
        updateLabels(); layer.batchDraw();
    });
    function stopPan() {
        if (!panPoint) return;
        panPoint = null;
        for (const item of items) item.group.draggable(!readonly);
    }
    stage.on('mouseup mouseleave', stopPan);
    window.addEventListener('mouseup', stopPan);
    const sheetOutline = new Konva.Line({points: sheet.flat(), closed: true, fill: '#f8fafc', stroke: '#334155', strokeWidth: 2, strokeScaleEnabled: false, listening: false});
    layer.add(sheetOutline);
    if (margin > 0 && model.chapa.largura_mm > margin * 2 && model.chapa.altura_mm > margin * 2) {
        layer.add(new Konva.Rect({x: margin, y: margin, width: model.chapa.largura_mm - margin * 2,
            height: model.chapa.altura_mm - margin * 2, stroke: '#d97706',
            strokeWidth: 2, strokeScaleEnabled: false, dash: [7, 5], listening: false}));
    }
    const rapidGroup = new Konva.Group({listening: false}); layer.add(rapidGroup);
    const transformer = readonly ? null : new Konva.Transformer({resizeEnabled: false, rotateEnabled: true, rotateAnchorOffset: 28, borderStroke: '#4f46e5', anchorStroke: '#4f46e5'});

    function snapshot(item) { return {x: item.group.x(), y: item.group.y(), rotation: item.group.rotation()}; }
    function restore(item, state) { item.group.position({x: state.x, y: state.y}); item.group.rotation(state.rotation); }
    function getWorld(item) {
        const local = rotatedRings(item.data.contornos, item.group.rotation());
        return local.rings.map(ring => ring.map(([x, y]) => [x + item.group.x() + local.minX, y + item.group.y() + local.minY]));
    }
    function updateLabels() {
        const occupied = [];
        // Prioritize the hovered piece. At full-sheet scale, omit labels that
        // cannot fit instead of masking the geometry with overlapping text.
        [...items].sort((a, b) => Number(Boolean(b.hovered)) - Number(Boolean(a.hovered))).forEach(item => {
            if (!item.label) return;
            const index = items.indexOf(item);
            const box = bounds(getWorld(item)[0]);
            const point = layer.getAbsoluteTransform().point({x: (box.minX + box.maxX) / 2, y: (box.minY + box.maxY) / 2});
            const text = `${item.data.nome ?? `Peça ${item.data.id}`} · ${index + 1}`;
            const width = text.length * 5.7 + 8, height = 15;
            const rect = {left: Math.max(2, Math.min(stage.width() - width - 2, point.x - width / 2)),
                top: Math.max(2, Math.min(stage.height() - height - 2, point.y - height / 2)), width, height};
            const fits = (box.maxX - box.minX) * Math.abs(layer.scaleX()) >= width &&
                (box.maxY - box.minY) * Math.abs(layer.scaleY()) >= height + 4;
            const overlaps = occupied.some(r => rect.left < r.left + r.width + 3 && rect.left + width + 3 > r.left &&
                rect.top < r.top + r.height + 3 && rect.top + height + 3 > r.top);
            const visible = point.x >= 0 && point.x <= stage.width() && point.y >= 0 && point.y <= stage.height() &&
                (item.hovered || (fits && !overlaps));
            item.label.textContent = text;
            item.label.style.display = visible ? 'block' : 'none';
            item.label.style.left = `${rect.left}px`;
            item.label.style.top = `${rect.top}px`;
            item.label.style.zIndex = item.hovered ? '12' : '10';
            if (visible) occupied.push(rect);
        });
    }
    function inside(item) {
        const world = getWorld(item), box = bounds(world[0]);
        return box.minX >= margin - 1e-5 && box.minY >= margin - 1e-5 &&
            box.maxX <= model.chapa.largura_mm - margin + 1e-5 &&
            box.maxY <= model.chapa.altura_mm - margin + 1e-5 && contained(world, sheet);
    }
    function tooClose(first, second) {
        const a = bounds(first[0]), b = bounds(second[0]);
        if (a.maxX + spacing <= b.minX + 0.001 || b.maxX + spacing <= a.minX + 0.001 ||
            a.maxY + spacing <= b.minY + 0.001 || b.maxY + spacing <= a.minY + 0.001) return false;
        return contoursTooClose(first, second, spacing);
    }
    let pathBindings = [];
    function toLocal(group, [x, y]) {
        const angle = group.rotation() * Math.PI / 180, cos = Math.cos(angle), sin = Math.sin(angle);
        const dx = x - group.x(), dy = y - group.y();
        return [dx * cos + dy * sin, -dx * sin + dy * cos];
    }
    function refreshRapids() {
        rapidGroup.destroyChildren();
        for (let i = 1; i < pathBindings.length; i++) {
            const before = pathBindings[i - 1], after = pathBindings[i];
            const start = before.item.group.getTransform().point(before.exit);
            const end = after.item.group.getTransform().point(after.entry);
            rapidGroup.add(new Konva.Line({points: [start.x, start.y, end.x, end.y],
                stroke: '#94a3b8', strokeWidth: 1, strokeScaleEnabled: false, dash: [6, 5], listening: false}));
        }
    }
    function renderCncPaths(paths) {
        for (const item of items) {
            for (const node of item.cncNodes ?? []) node.destroy();
            item.cncNodes = [];
        }
        pathBindings = [];
        for (const path of paths ?? []) {
            const item = items.find(candidate => String(candidate.data.id) === String(path.id)
                && candidate.data.instancia === path.instancia);
            if (!item) continue;
            // Thin screen-space strokes keep the visual path from hiding a 3 mm real clearance.
            for (const [points, color, width] of [[path.corte, '#be123c', 1],
                [path.entrada, '#d97706', 1], [path.saida, '#d97706', 1], [path.tab ?? [], '#16a34a', 2]]) {
                if (points.length < 2) continue;
                const line = new Konva.Line({points: points.flatMap(point => toLocal(item.group, point)),
                    stroke: color, strokeWidth: width, strokeScaleEnabled: false, listening: false});
                item.group.add(line); item.cncNodes.push(line);
            }
            const pierce = toLocal(item.group, path.entrada[0]);
            const marker = new Konva.Circle({x: pierce[0], y: pierce[1], radius: 4,
                fill: '#7c3aed', stroke: '#fff', strokeWidth: 1, strokeScaleEnabled: false, listening: false});
            item.group.add(marker); item.cncNodes.push(marker);
            pathBindings.push({item, entry: toLocal(item.group, path.entrada[0]),
                exit: toLocal(item.group, path.saida[1])});
        }
        refreshRapids();
        layer.batchDraw();
    }
    function refreshCollisions() {
        const collisions = new Set();
        for (let i = 0; i < items.length; i++) for (let j = i + 1; j < items.length; j++) {
            if (tooClose(getWorld(items[i]), getWorld(items[j]))) { collisions.add(items[i]); collisions.add(items[j]); }
        }
        for (const item of items) {
            item.outer.fill(collisions.has(item) ? 'rgba(239,68,68,0.45)' : 'rgba(20,184,166,0.35)');
            item.outer.stroke(collisions.has(item) ? '#b91c1c' : '#0f766e');
        }
        event('colisao-atualizada', collisions.size > 0);
        if (pathBindings.length) refreshRapids();
        updateLabels();
        layer.batchDraw();
        return collisions.size > 0;
    }
    for (const data of model.pecas_posicionadas) {
        const original = rotatedRings(data.contornos, data.rotacao_graus);
        const group = new Konva.Group({x: data.x - original.minX, y: data.y - original.minY, rotation: data.rotacao_graus, draggable: !readonly});
        const outer = new Konva.Line({points: data.contornos[0].flat(), closed: true, fill: 'rgba(20,184,166,0.35)', stroke: '#0f766e', strokeWidth: 1, strokeScaleEnabled: false});
        group.add(outer);
        for (const hole of data.contornos.slice(1)) group.add(new Konva.Line({points: hole.flat(), closed: true, fill: '#f8fafc', stroke: '#0f766e', strokeWidth: 1, strokeScaleEnabled: false}));
        const item = {data, group, outer, lastValid: null}; item.lastValid = snapshot(item);
        const label = document.createElement('span');
        label.style.cssText = 'position:absolute;pointer-events:none;white-space:nowrap;background:#ffffffd9;color:#14253c;font:600 10px/15px sans-serif;padding:0 3px;border-radius:3px;';
        label.setAttribute('aria-label', `Código e ordem de ${data.nome ?? `Peça ${data.id}`}`);
        container.append(label);
        item.label = label;
        if (!readonly) {
            group.on('mouseenter', () => { container.style.cursor = 'grab'; item.hovered = true; updateLabels(); });
            group.on('mouseleave', () => { container.style.cursor = 'default'; item.hovered = false; updateLabels(); });
            group.on('dragstart', () => { container.style.cursor = 'grabbing'; });
            group.on('click tap', () => { transformer.nodes([group]); layer.batchDraw(); });
            group.on('dragmove transform', () => {
                if (!inside(item)) restore(item, item.lastValid);
                else item.lastValid = snapshot(item);
                refreshCollisions();
            });
            group.on('dragend transformend', () => { item.lastValid = snapshot(item); refreshCollisions(); });
        }
        layer.add(group); items.push(item);
    }
    if (transformer) layer.add(transformer);
    if (!readonly) stage.on('click tap', e => { if (e.target === stage || e.target === sheetOutline) { transformer.nodes([]); layer.batchDraw(); } });
    renderCncPaths(model.trajetos_cnc);
    refreshCollisions();
    stage.draw();
    if (readonly) return;

    const order = document.getElementById('cut-order');
    let persistChanges = () => {};
    function renderOrder() {
        order.replaceChildren();
        items.forEach((item, index) => {
            const li = document.createElement('li'); li.className = 'flex items-center justify-between gap-2';
            const label = document.createElement('span'); label.textContent = `${index + 1}. ${item.data.nome ?? `Peça ${item.data.id}`}`;
            const controls = document.createElement('span');
            for (const [symbol, step] of [['↑', -1], ['↓', 1]]) {
                const button = document.createElement('button'); button.type = 'button'; button.textContent = symbol;
                button.className = 'mx-1 rounded border px-2'; button.disabled = index + step < 0 || index + step >= items.length;
                button.addEventListener('click', () => {
                    [items[index], items[index + step]] = [items[index + step], items[index]];
                    renderOrder(); updateLabels();
                    if (commitHistory()) persistChanges();
                });
                controls.append(button);
            }
            li.append(label, controls); order.append(li);
        });
    }
    renderOrder();
    let history = [JSON.stringify(positions(items))], historyIndex = 0;
    const undoButton = document.getElementById('desfazer-arranjo');
    const redoButton = document.getElementById('refazer-arranjo');
    function updateHistoryButtons() {
        if (undoButton) undoButton.disabled = historyIndex === 0;
        if (redoButton) redoButton.disabled = historyIndex >= history.length - 1;
    }
    function commitHistory() {
        const current = JSON.stringify(positions(items));
        if (current === history[historyIndex]) return false;
        history = history.slice(0, historyIndex + 1);
        history.push(current);
        if (history.length > 51) history.shift();
        historyIndex = history.length - 1;
        updateHistoryButtons();
        return true;
    }
    function restoreHistory(index) {
        if (index < 0 || index >= history.length) return;
        historyIndex = index;
        const stored = JSON.parse(history[index]);
        const byInstance = new Map(items.map(item => [item.data.instancia, item]));
        const ordered = [];
        for (const position of stored) {
            const item = byInstance.get(position.instancia);
            const local = rotatedRings(item.data.contornos, position.rotacao_graus);
            item.group.rotation(position.rotacao_graus);
            item.group.position({x: position.x - local.minX, y: position.y - local.minY});
            item.lastValid = snapshot(item);
            ordered.push(item);
        }
        items.splice(0, items.length, ...ordered);
        renderOrder(); refreshCollisions(); updateHistoryButtons(); persistChanges();
    }
    undoButton?.addEventListener('click', () => restoreHistory(historyIndex - 1));
    redoButton?.addEventListener('click', () => restoreHistory(historyIndex + 1));
    window.addEventListener('keydown', key => {
        if (!(key.ctrlKey || key.metaKey) || key.altKey || /INPUT|TEXTAREA|SELECT/.test(document.activeElement?.tagName ?? '')) return;
        if (key.key.toLowerCase() === 'z') { key.preventDefault(); restoreHistory(historyIndex + (key.shiftKey ? 1 : -1)); }
        if (key.key.toLowerCase() === 'y') { key.preventDefault(); restoreHistory(historyIndex + 1); }
    });
    for (const item of items) item.group.on('dragend transformend', () => {
        if (commitHistory()) persistChanges();
    });
    updateHistoryButtons();
    if (preview) {
        let saveChain = Promise.resolve();
        async function savePreview() {
            message('');
            if (refreshCollisions()) {
                event('editor-salvamento', 'Ajuste as peças sobrepostas');
                throw new Error('Há peças sobrepostas. Reposicione antes de salvar.');
            }
            event('editor-salvamento', 'Salvando posições...');
            try {
                const result = await post(config.dataset.salvar, {plano: Number(config.dataset.plano), posicoes: positions(items)});
                const tempoChapa = document.getElementById('tempo-chapa');
                const tempoLote = document.getElementById('tempo-lote');
                if (tempoChapa && tempoLote) {
                    tempoLote.textContent = Number(tempoLote.textContent) - Number(tempoChapa.textContent) + result.tempo_corte_estimado_seg;
                    tempoChapa.textContent = result.tempo_corte_estimado_seg;
                }
                const area = document.getElementById('area-chapa');
                if (area) area.textContent = Number(result.area_utilizada_pct).toLocaleString('pt-BR', {minimumFractionDigits: 2, maximumFractionDigits: 2});
                renderCncPaths(result.trajetos_cnc);
                event('editor-salvamento', 'Posições salvas');
                return result;
            } catch (error) {
                event('editor-salvamento', 'Falha ao salvar');
                throw error;
            }
        }
        persistChanges = () => {
            saveChain = saveChain.catch(() => {}).then(savePreview);
            saveChain.catch(error => message(error.message));
        };
        for (const link of document.querySelectorAll('[aria-label="Chapas do lote"] a')) {
            link.addEventListener('click', async click => {
                click.preventDefault();
                try { await saveChain; window.location.assign(link.href); }
                catch (error) { message(error.message); }
            });
        }
        const exportar = document.getElementById('exportar-arranjo');
        if (exportar) exportar.addEventListener('click', async () => {
            try { await saveChain.catch(() => {}); await savePreview(); document.getElementById('exportar-form').requestSubmit(); }
            catch (error) { message(error.message); }
        });
        return;
    }
    document.getElementById('recalcular-tempo').addEventListener('click', async () => {
        try {
            message('');
            const result = await post(config.dataset.recalcular, {posicoes: positions(items)});
            event('tempo-atualizado', result.tempo_corte_estimado_seg);
            document.getElementById('area-chapa').textContent = Number(result.area_utilizada_pct).toLocaleString('pt-BR', {minimumFractionDigits: 2, maximumFractionDigits: 2});
            renderCncPaths(result.trajetos_cnc);
        }
        catch (error) { message(error.message); }
    });
    const organizeButton = document.getElementById('organizar-pedido');
    organizeButton?.addEventListener('click', async () => {
        organizeButton.disabled = true;
        message('Organizando as peças pelos contornos. Isso pode levar alguns segundos...');
        try {
            const result = await post(config.dataset.organizar, {});
            const byInstance = new Map(items.map(item => [item.data.instancia, item]));
            const ordered = [];
            for (const position of result.posicoes) {
                const item = byInstance.get(position.instancia);
                const local = rotatedRings(item.data.contornos, position.rotacao_graus);
                item.group.rotation(position.rotacao_graus);
                item.group.position({x: position.x - local.minX, y: position.y - local.minY});
                item.lastValid = snapshot(item);
                ordered.push(item);
            }
            items.splice(0, items.length, ...ordered);
            renderOrder(); refreshCollisions(); commitHistory();
            event('tempo-atualizado', result.tempo_corte_estimado_seg);
            document.getElementById('area-chapa').textContent = Number(result.area_utilizada_pct).toLocaleString('pt-BR', {minimumFractionDigits: 2, maximumFractionDigits: 2});
            renderCncPaths(result.trajetos_cnc);
            message('Novo arranjo pronto. Clique em Salvar alterações e atualizar CNC para aplicar ao pedido.');
        } catch (error) { message(error.message); }
        finally { organizeButton.disabled = false; }
    });
    document.getElementById('confirmar-arranjo').addEventListener('click', async () => {
        if (refreshCollisions()) { message('Há peças sobrepostas. Reposicione antes de salvar.'); return; }
        try { message(''); await post(config.dataset.confirmar, {posicoes: positions(items)}); window.location.assign(config.dataset.pedido); }
        catch (error) { message(error.message); }
    });
}
