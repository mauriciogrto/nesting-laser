"""Adaptive non-orthogonal refinement over exact compensated part contours.

Angles derived from long edges supplement coarse turns; local fractional
adjustments follow improvements. Each trial repositions the entire contour in
the feasible translation region, rather than rotating it in place only.
"""
import math
import random
import time

from compact_nesting import RegionPacker, area_score


def directions(polygon):
    points = list(polygon.simplify(1, preserve_topology=True).exterior.coords)
    edges = sorted(((math.dist(a, b), math.degrees(math.atan2(b[1]-a[1], b[0]-a[0])) % 180)
                    for a, b in zip(points, points[1:])), reverse=True)
    selected = []
    for length, angle in edges:
        if length > 2 and all(abs((angle-old+90) % 180-90) > 3 for old in selected):
            selected.append(angle)
        if len(selected) == 3:
            break
    return selected


def solve(payload, seconds=15, baseline=None):
    deadline = time.monotonic()+max(.05, min(float(seconds), 300))
    packer = RegionPacker(payload, deadline)
    best = packer.import_layout((baseline or {}).get('pecas_posicionadas', []))
    if len(best) != len(packer.parts):
        # Try both material-saving directions and several orders. A single
        # large-first pass can strand a diagonal part even when all pieces fit.
        by_area = sorted(packer.parts,key=lambda p: packer.shapes[p[0]][1],reverse=True)
        by_length = sorted(packer.parts,key=lambda p: max(packer.shapes[p[0]][2][0][2:]),reverse=True)
        for order in (by_area, by_length, list(reversed(by_area))):
            for prefer_top in (True, False):
                if time.monotonic() >= deadline:
                    break
                candidate = []
                for part in order:
                    if time.monotonic() >= deadline:
                        break
                    base = packer.shapes[part[0]][2][0][0]
                    width, height = packer.shapes[part[0]][2][0][2:]
                    free_width = packer.width - 2*packer.margin
                    free_height = packer.height - 2*packer.margin
                    cardinal_fits = ((width <= free_width and height <= free_height) or
                                     (height <= free_width and width <= free_height))
                    # Search intermediate turns when the part cannot fit the
                    # sheet at cardinal angles; otherwise use edge-aligned and
                    # diagonal seeds to keep the bounded search responsive.
                    degrees = ([0,90,180,270,45,135,225,315] if cardinal_fits
                               else list(range(0,360,15)))
                    for edge in directions(base):
                        degrees.extend((-edge,90-edge,180-edge,270-edge))
                    angles = list(dict.fromkeys(packer.angle_index(part[0],a) for a in degrees))
                    item = packer.place(part,candidate,prefer_top=prefer_top,angles=angles)
                    if item is not None:
                        candidate.append(item)
                if len(candidate)>len(best) or (len(candidate)==len(best) and
                        area_score(candidate,packer.width,packer.height)>area_score(best,packer.width,packer.height)):
                    best = candidate
                if len(best) == len(packer.parts):
                    break
            if len(best) == len(packer.parts):
                break
        if len(best) != len(packer.parts):
            return {'pecas_posicionadas': packer.export(best), 'tentativas': 1}
    rng = random.Random(int(payload.get('quality_seed',42)))
    attempted = set()
    trials = 0
    while time.monotonic() < deadline:
        top = packer.width*(packer.height-max(p.y+p.height for p in best))
        right = packer.height*(packer.width-max(p.x+p.width for p in best))
        prefer_top = top >= right
        extent = lambda p: p.y+p.height if prefer_top else p.x+p.width
        ranked = sorted(best, key=extent, reverse=True)
        # Alternate the frontier and interior pieces: neutral compacting moves
        # can open the pocket required by a later frontier move.
        chosen = ranked[:8] if trials % 3 else ranked
        old = chosen[(trials//3) % len(chosen)] if trials % 3 else rng.choice(chosen)
        others = [p for p in best if p.instance != old.instance]
        current = packer.angle_values[old.shape][old.angle]
        base = packer.shapes[old.shape][2][0][0]
        angles = [current+delta for delta in (15,-15,30,-30,45,-45,7.5,-7.5,2.5,-2.5,.5,-.5)]
        for angle in directions(base):
            angles.extend((-angle,90-angle,180-angle,270-angle))
        neighbours = sorted(others, key=lambda p: p.polygon.distance(old.polygon))[:2]
        for neighbour in neighbours:
            for fixed in directions(neighbour.polygon):
                for moving in directions(base):
                    angles.extend((fixed-moving, fixed-moving+180))
        options = [round(a%360,3)%360 for a in angles]
        options = [a for a in dict.fromkeys(options) if (old.instance,current,a) not in attempted and abs(a-current)>.001]
        if not options:
            if trials > len(best)*100:
                break
            trials += 1
            continue
        degrees = options[0]
        attempted.add((old.instance,current,degrees))
        angle = packer.angle_index(old.shape,degrees)
        if trials % 5 == 4:
            # Give a rotated contour room to interlock: removing only that
            # piece cannot escape a pocket locked by its neighbours.
            nearby = sorted(others,key=lambda p:p.polygon.distance(old.polygon))
            removed = [old]+nearby[:rng.randint(1,min(4,len(nearby)))] if nearby else [old]
            identities = {p.instance for p in removed}
            candidate = [p for p in best if p.instance not in identities]
            for entry in sorted(removed,key=lambda p:p.polygon.area,reverse=True):
                orientations = list(dict.fromkeys([entry.angle,0,1,2,3]+([angle] if entry.instance==old.instance else [])))
                item = packer.place((entry.shape,entry.id,entry.instance),candidate,prefer_top,angles=orientations)
                if item is not None:
                    candidate.append(item)
        else:
            item = packer.place((old.shape,old.id,old.instance),others,prefer_top,angles=[angle])
            candidate = others+[item] if item is not None else []
        if len(candidate)==len(best):
            gain = area_score(candidate,packer.width,packer.height)-area_score(best,packer.width,packer.height)
            if gain > .001 or (abs(gain)<=.001 and sum(map(extent,candidate))<sum(map(extent,best))-.01):
                best = candidate
                # Revisit previously attempted angles after the neighbourhood
                # changes; fractional adjustments now refine the accepted turn.
                attempted = {key for key in attempted if key[0] == old.instance}
        trials += 1
    return {'pecas_posicionadas':packer.export(best),'tentativas':trials}
