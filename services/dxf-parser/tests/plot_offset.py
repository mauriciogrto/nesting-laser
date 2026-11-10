"""Save a side-by-side rectangle-with-hole offset plot for manual visual review."""

import importlib.util
import sys
from pathlib import Path

import matplotlib
matplotlib.use("Agg")
import matplotlib.pyplot as plt

module_path = Path(__file__).resolve().parents[1] / "aplicar_offset.py"
spec = importlib.util.spec_from_file_location("aplicar_offset", module_path)
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)

outer = [[0, 0], [100, 0], [100, 60], [0, 60]]
hole = [[40, 25], [60, 25], [60, 35], [40, 35]]
result = module.apply_offset({"contornos": [outer, hole], "offset_mm": -2})["contornos"]

fig, axes = plt.subplots(1, 2, figsize=(12, 4))
for axis, contours, title in zip(axes, [[outer, hole], result], ["Original", "Offset -2 mm (ampliado para inspeção visual)"]):
    for contour in contours:
        closed = contour + [contour[0]]
        axis.plot([p[0] for p in closed], [p[1] for p in closed])
    axis.set_title(title)
    axis.set_xlim(-1, 101)
    axis.set_ylim(-1, 61)
    axis.set_aspect("equal")
    axis.grid(True)
fig.tight_layout()
output = Path(sys.argv[1]) if len(sys.argv) > 1 else Path("offset-validation.png")
fig.savefig(output, dpi=180)
print(output)
