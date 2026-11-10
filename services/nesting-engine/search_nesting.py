"""Bounded portfolio: repeated columns, then joint-contact region repacking.

The complete validated incumbent is a quality floor, including a saved layout
when reorganizing. No code-specific positions or benchmark shapes are used.
"""
import json
import sys
import time

from compact_nesting import RegionPacker, area_score, solve as repack
from quality_nesting import solve as legacy
from angular_nesting import solve as angular


def solve(payload, seconds=45):
    started = time.monotonic()
    budget = max(2, min(float(seconds), 120))
    checker = RegionPacker(payload, started + budget)
    candidates = [payload.get('baseline') or {}, payload.get('incumbent') or {}]
    if len(checker.parts) == 1:
        # Do not spend the full batch-search budget on a single contour. The
        # angular phase can find oblique-only fits and refine them directly.
        placed, _ = max(((checker.import_layout(c.get('pecas_posicionadas', [])), c)
                         for c in candidates), key=lambda pair: (len(pair[0]), area_score(pair[0], checker.width, checker.height)))
        return angular(payload, min(budget, 2), {'pecas_posicionadas': checker.export(placed)})
    candidates.append(legacy(payload, budget * .3))
    valid = [(checker.import_layout(c.get('pecas_posicionadas', [])), c) for c in candidates]
    placed, _ = max(valid, key=lambda pair: (len(pair[0]), area_score(pair[0], checker.width, checker.height)))
    baseline = {'pecas_posicionadas': checker.export(placed)}
    remaining = max(1, budget - (time.monotonic() - started))
    compacted = repack(payload, remaining * .65, baseline)
    remaining = budget - (time.monotonic() - started)
    if remaining < .1:
        return compacted
    refined = angular(payload, remaining, compacted)
    refined['tentativas'] += compacted.get('tentativas',0)
    return refined


if __name__ == '__main__':
    print(json.dumps(solve(json.load(sys.stdin), float(sys.argv[1]) if len(sys.argv) > 1 else 45)))
