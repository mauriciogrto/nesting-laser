# Research

Checked on 2026-09-24.

- Laravel 13 requires PHP >= 8.3: https://laravel.com/framework/docs/releases
- Official PHP Windows builds: https://www.php.net/downloads.php?os=windows
- MySQL ZIP installation supports a project-local server: https://dev.mysql.com/doc/refman/8.4/en/windows-installation.html
- SVGnest's documented NFP + genetic approach: https://github.com/Jack000/SVGnest
- The server-side SVGnest port describes itself as incomplete: https://github.com/FrankSandqvist/svg-nest
- Deepnest's Node fork requires native bindings and older prerequisites: https://github.com/ShaMan123/Deepnest
- Polygon clipping library API: https://www.npmjs.com/package/polygon-clipping

Checked on 2026-10-02 for the next irregular-nesting experiment:

- A 2023 irregular-bin-packing research paper describes replacement, swap, partial repack and full repack operations alongside no-fit polygons: https://www.sciencedirect.com/science/article/pii/S0305054823002629 . These are candidate methods for escaping the current greedy placement's local optimum; the paper's benchmark claims are not evidence of savings on the user's DXFs.
- An extended local-search paper includes arcs and holes, which match this lot's geometry better than box-only placement: https://www.sciencedirect.com/science/article/pii/S0305054811001596 . The system would still need its own full-contour, kerf and clearance validation after each proposed move.
