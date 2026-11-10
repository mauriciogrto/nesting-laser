# Decisions

## 2026-10-05 — Four cut settings in Otimizar

- Decision: show only kerf, safe distance between pieces, external sheet margin and lead-in in the new-arrangement and preview reorganization forms. Initialize them from the selected thickness. An arrangement may increase distance or margin independently, but neither may fall below the thickness profile's safety minimum; distance must also exceed kerf. Remove tabs from these quick forms and default new arrangements to no tabs. Preserve tabs already saved on a revised order and retain their management control on the order. This supersedes the 2026-10-02 editor decision only for the cut-settings UI.
- Context: the user found the technical summary and tab controls confusing while creating an arrangement and asked for four named settings; they asked whether tabs are necessary.
- Rationale: the four visible numbers correspond directly to the engine inputs, without silently clamping margin to piece spacing. Tabs are a specialised production choice for small parts and do not belong in the routine optimization path.

## 2026-10-02 — Simplified piece catalog and three order sections

- Decision: show the registered piece's name, DXF format and dimensions in the catalog list, omitting the internal filename and processing-status column. Divide the order detail into Pedido (parts, sheets, downloads and arrangement), Gestão (dates, time and production actions) and Custos (material, machine and total). Place return actions in the upper-right page heading on subpages.
- Context: the user requested a simpler piece list, three sections when opening an order, removal of the cutting-measure card and consistent top-right back buttons.
- Rationale: operators can find the actions and information they need without reading internal file or processing details. The cut settings remain available in the arrangement editor, where they can be changed safely before reoptimization.

## 2026-10-02 — Full-batch editing before cutting

- Decision: editing a ready, uncut arrangement replaces the desired piece quantities as one batch, including reductions and removals, after a preview and explicit confirmation. The editor lists the current pieces, adds DXFs/catalog parts through a modal, and shows kerf, safe margin, profile thermal clearance, lead-in and tabs. Material and thickness remain bound to the reserved sheets. Existing add-only requests without the replacement flag continue to work.
- Context: the user asked to edit existing quantities and remove items directly from the arrangement editor, with a popup for additional pieces and the cutting settings below the list.
- Rationale: a complete desired list makes the preview and accepted order agree, while the existing transactional confirmation preserves inventory and CNC consistency.

## 2026-10-02 — Allow diagonal and fractional automatic rotations

- Decision: automatic nesting may use diagonal and fractional angles, not only multiples of 90 degrees. Add adaptive edge-alignment angles, coarse offsets and local fractional refinements, with neighbouring-group repacking; preserve exact contour clearance, quantities and the incumbent quality floor.
- Context: the user identified an internal void above `16865` and explicitly confirmed that oblique orientations are allowed.
- Rationale: cardinal orientations can exclude feasible interlocks. An enabled angular search is not proof of further savings: update the saved benchmark only after a larger reusable remnant is independently validated.

## 2026-10-02 — Large-first groups, hole fillers and containment-aware cutting

- Decision: prioritize the real 31-piece benchmark before promising improvements across arbitrary user orders. Build large-part arrangements, search joint contacts against multiple neighbours, remove/reinsert groups and offer remaining pieces to the actual cutouts. Preserve a validated saved arrangement as an incumbent during reorganization; never trade fewer required instances for a larger remnant.
- Context: the user contrasted the manual and app screenshots and explicitly requested compact large-piece organization followed by small pieces inside holes and gaps.
- Rationale: greedy pairwise contact vertices missed multi-neighbour pockets; bounding-box hole corners missed rounded openings. The objective remains largest reusable rectangular remnant with the configured kerf/clearance, not number of parts in holes or a guaranteed global optimum. Cutting containment dependencies supersede the unconditional all-holes-before-all-exteriors rule: nested pieces must be finished before releasing their host cutout.

## 2026-10-02 — Separate part coverage, reusable remnant and internal gaps

- Decision: show these three percentages independently on each saved sheet. Label the existing nesting percentage as area inside part contours, because moving a fixed lot cannot change that numerator. Keep the registered rectangular remnant distinct from remaining voids and holes; only count the remnant as an economic gain if production can use it later.
- Context: the user saw the 36.47% coverage of Pedido #15 and perceived the large empty top strip as poor nesting, while that strip is a 1,500 × 1,391.748 mm stock remnant. The actual in-envelope voids and holes are about 17.14% of the original sheet.
- Rationale: optimizing positions should be judged by reusable remnant and/or fewer physical sheets, not by an invariant coverage figure.

## 2026-10-02 — Match the supplied A36 19.05 mm CNC for this benchmark

- Decision: retain the existing 10 mm profile clearances except for the A36 19.05 mm benchmark profile (#9), set to 2.1 mm between compensated cut contours and at the sheet edge. Keep kerf at 2 mm and lead-in at 2 mm. Allow an operator to edit a profile to a value below 10 mm when it remains strictly greater than kerf; the profile value, not a global hardcoded floor, controls nesting and later editing. Delete the old ready orders through the inventory reversal path and create one replacement order for the 31-piece lot.
- Context: the latest user request explicitly calls for the widths and spacing of `A36_19,05MM_1.CNC`, replacing the earlier blanket 10 mm decision for this comparison. Distinct manual external cut paths have a measured 2.115 mm gap; its pierce/edge behavior differs from the app, so the 2.1 mm profile is an inferred comparison setting, not machine calibration.
- Rationale: the former 10 mm rule made automatic-versus-manual sheet consumption incomparable. A profile-specific exception preserves existing settings elsewhere and makes the saved CNC, editor and optimizer agree on the same cut clearance.

## 2026-10-02 — Prioritize recoverable stock over a fixed fill direction

- Decision: for detailed parts on rectangular stock, try both left-to-right and bottom-up arrangements and select the complete valid layout with the largest intact rectangular remnant, whether at the right or top. Include feasible placements inside interior cutouts without reducing the configured 10 mm cut-contour clearance. Keep left-to-right as a tie preference, rather than a hard objective.
- Context: the earlier left-to-right preference could leave a smaller usable remnant than a bottom-packed layout; the user now prioritizes sheet cost and waste and supplied a manual example with nested parts.
- Rationale: the amount of reusable stock, not the orientation of the fill, determines the estimated net sheet consumption. The manual CNC remains a comparison reference, not a source of copied placements.

## 2026-10-02 — Revise an exported order before cutting

- Decision: an exported order remains editable until production begins. Adding DXFs or catalog quantities creates a temporary complete-lot preview; acceptance replaces the arrangements inside the same order, preserving one order ID even when more sheets are needed. The stock debit and unused remnants are reversed and reapplied atomically. If an old remnant has been consumed or the order changed since preview, confirmation is blocked.
- Context: the user sometimes has an arrangement ready but has not cut the sheet and needs to add new parts.
- Rationale: the revised CNC and stock must describe the same physical lot without producing duplicate orders or losing inventory history.

## 2026-10-01 — Minimal sheet registration and DXF-based piece names

- Decision: register a new sheet by material, material thickness, width and height only. Each submission creates one available new sheet; origin, stock count and zero initial cost are set internally. Editing those four fields preserves the sheet's stock and history fields. If a piece name is blank, use the uploaded DXF filename without its extension; when editing without a replacement file, retain the current name.
- Context: the user clarified the sheet form and requested automatic piece naming from the DXF.
- Rationale: operators should only enter physical sheet attributes, while inventory bookkeeping remains system-managed. Optional naming keeps repeated DXF uploads fast without losing a meaningful code.

## 2026-10-01 — New 31-piece benchmark and report

- Decision: use the ten DXF filenames as piece codes and the quantities shown in the user's images, correcting the three mistyped codes in the accompanying text. Produce one order with internal sheets when needed and compare its 1,500 × 3,000 mm sheet use with the manual report on a clearly stated common basis. Keep the PDF's nominal piece-area use separate from its reusable rectangular remnant.
- Context: the user supplied a new manual layout/report and confirmed that the attached DXFs, including `08339`, `07130_02` and `07134_02`, are authoritative. `17414.DXF` was supplied in a later message.
- Rationale: the comparison must describe the actual lot and distinguish fixed part area from material savings. Both the original drawings and the saved compensated cut path are needed to explain these different figures.

## 2026-10-01 — Dashboard visual language

- Decision: use the dark variant of the supplied DashStack Figma kit across the application, while keeping the NestLaser name, Portuguese labels, existing workflow and live order data.
- Context: the linked Figma node is the dark main background; related dark sidebar, top bar and button components establish the palette and spacing.
- Rationale: one shared shell and visual tokens keep all modules consistent, with a prominent new-arrangement action, readable secondary/download actions and a responsive navigation menu.

## 2026-09-24 — Sequential implementation

- Decision: use the supplied roadmap and validate phases independently.
- Context: new project with no existing implementation.
- Rationale: requested by the user and required by the roadmap to avoid accumulating hidden errors.
- User explicitly selected automatic progression through validated phases on 2026-09-24; no approval checkpoint between phases.

## 2026-09-24 — Isolated local tooling

- Decision: use project-local PHP and MySQL, without modifying global XAMPP configuration.
- Context: the installed PHP is 8.2.12 and XAMPP includes MariaDB 10.4.32; current Laravel requires PHP >= 8.3 and the roadmap requests MySQL.
- Rationale: meet the requested stack while preserving other local projects.

## 2026-09-24 — Headless nesting engine strategy

- Decision: adapt SVGnest's NFP plus genetic-order strategy as a small Node service using pure-JavaScript polygon libraries, with exact polygon validation before accepting placement.
- Context: upstream SVGnest depends on browser workers; a Node port describes itself as incomplete, and the Deepnest Node fork requires legacy Node, Python and native C++ bindings. This workspace has Node 22 and Python 3.14.
- Rationale: keep the algorithm headless and testable on the local runtime while retaining NFP candidate generation and genetic search.

## 2026-09-24 — Konva editor

- Decision: use Konva.js for the arrangement editor.
- Context: the editor needs draggable groups, a rotation handle, canvas scaling and real polygon outlines.
- Rationale: Konva provides these primitives directly and keeps the placement model independent of canvas pixels.

## 2026-09-24 — Native access control and audit

- Decision: use Laravel Gate for admin/operator access and Eloquent observers for critical audit events.
- Context: the roadmap needs only two roles and an append-only log for order/stock changes.
- Rationale: native framework capabilities keep dependencies and permissions simple; observer registration centralizes logging.

## 2026-09-24 — Stored report metrics

- Decision: persist exact sheet and compensated-piece areas per order when nesting finishes, with a one-time backfill.
- Context: multi-contour JSON geometry is costly to aggregate in SQL at report time.
- Rationale: reports can filter and aggregate metrics in the database without loading every order or recomputing geometry.

## 2026-09-24 — Temporary batch preview before order creation

- Decision: hold batch DXF geometry and nesting results in a 15-minute cache, with private temporary files and scheduled cleanup; create domain records only when the operator accepts a complete arrangement.
- Context: the requested Arranjo Rápido flow allows trying different sheets without repeating DXF extraction or filling the catalog with abandoned pieces.
- Rationale: retries remain fast and reversible, while acceptance preserves the existing ready-order data model. Incomplete arrangements stay inspectable but cannot become production orders.

## 2026-09-24 — Quantity-aware multi-sheet batches

- Decision: accept up to 20 DXF drawings and 100 requested instances per batch, automatically allocate across compatible available sheets with remnants first, exclude stock already allocated to active orders, and create one ready order per physical sheet used.
- Context: the user needs cases such as three drawings at 20 units each, where one sheet cannot hold the full quantity.
- Rationale: the existing order model represents one sheet per order, while the preview can coordinate the complete batch and block acceptance when stock is insufficient. The 100-instance limit matches the current nesting engine contract; the 20-file cap matches local PHP upload capacity.

## 2026-09-24 — Export-centered workflow and inventory

- Decision: the primary tabs are Materiais, Chapas, Peças, Pedidos and Otimizar. Otimizar accepts uploaded DXFs and catalog pieces, allows manual placement and automatic reorganization, and creates editable orders on export. Export immediately debits each used physical sheet and automatically records the conservative reusable remnant; completion only records production results.
- Context: the user replaced the Arranjo Rápido and completion-centered flow with an optimizer and managerial orders, and explicitly selected export as the inventory event.
- Rationale: the exported arrangement reserves the actual stock consumption and leaves the remaining usable stock visible at once. This supersedes the earlier completion-time stock decision for new orders. Used sheet records remain for inventory history even when their available quantity reaches zero.

## 2026-09-24 — Automatic order costs

- Decision: store sheet unit cost and machine-hour rate per material thickness; calculate order material cost after assigning proportional value to any remnant and machine cost from estimated or actual cutting seconds.
- Context: the user selected automatic sheet and machine-hour costing in Pedidos.
- Rationale: orders show a consistent material, machine and total estimate at export and can update the machine amount when actual time is known. Existing rows default to zero until rates are entered.

## 2026-09-24 — Search-to-add catalog pieces in Otimizar

- Decision: show a search box for processed catalog pieces in Otimizar, then list only pieces explicitly added to the arrangement with their quantities and remove controls.
- Context: the user requested that Otimizar stop displaying every registered piece.
- Rationale: the form stays usable as the catalog grows while retaining the ability to combine stored pieces with new DXFs.

## 2026-09-24 — Direct manipulation in the optimization preview

- Decision: move pieces by dragging directly on the canvas, update their outlines during the drag, and save the valid placement automatically when the mouse is released. Provide three downloadable DXF examples and a one-click path through the real preview.
- Context: the user reported an apparently blank preview and requested square, circular and triangular drawings to see the layout and edit pieces in real time.
- Rationale: the examples make the flow immediately inspectable, and automatic saving removes an extra manual step after every move.

## 2026-09-25 — CNC output and real DXF validation

- Decision: CNC is the only download format for new and edited orders. The preview displays the planned cutting and lead-in/out paths used by that CNC. Remove the former square/circle/triangle examples and use the three supplied DXFs in a local order named `teste`, three units of each on a 3,000 × 1,200 mm sheet.
- Context: the user supplied a machine CNC reference and replaced the earlier DXF-output and example-preview requests.
- Rationale: operators need to review the actual cut paths before exporting and hand the machine a program in its expected format. Machine settings remain material-specific; the local test material uses zero kerf only because no measured kerf was provided.

## 2026-09-25 — Preview safety controls and reversible edits

- Decision: allow an arrangement-specific kerf override, sheet-edge margin and conservative minimum clearance between pieces. Changing these parameters reruns compensation and nesting. The same margin constrains CNC lead-in/out; saved orders retain the settings for later edits and CNC regeneration. Undo/redo covers moves, rotations and cut order within the current editor session. Code and cut-order labels are visual only, and sheet utilization appears directly below estimated time.
- Context: the user approved staged editor improvements and requested labels on parts plus utilization next to time.
- Rationale: settings are visible before export, validation enforces them on automatic and manual layouts, and the output follows the reviewed geometry. Geometry redrawing and machine keep-out zones remain separate work because they require an editable drawing model and additional validation.

## 2026-09-25 — Left-to-right automatic placement

- Decision: fill the sheet from its left edge toward the right, placing into available vertical space at the smallest X before moving farther right. Among layouts with equal placed count and area, prefer the smallest occupied width.
- Context: the user asked for optimization to progress from the sheet's side, left to right.
- Rationale: this concentrates a reusable full-height strip on the right of typical wide sheets. Manual moves remain possible, and the remnant calculation still picks the larger conservative free rectangle.

## 2026-09-25 — Contour-aware quality search and visual PDF

- Decision: keep the conservative Node layout as a bounded fallback, then try a Python contour-contact search on detailed drawings in rectangular sheets. Accept an alternative only after full geometry validation and a larger reusable rectangular remnant; editing an exported order remains an explicit saved-order action. Generate the visual PDF on demand from the saved compensated geometry, alongside the CNC download.
- Context: the user's real Pedido #5 fitted all 24 pieces but left only a narrow reusable strip, while the supplied manual example interlocked concave profiles.
- Rationale: sampled Minkowski contacts are fast enough to propose tight positions, Shapely verifies complete outlines and holes, and the existing order edit path keeps inventory, remnant and CNC consistent. On-demand PDF always reflects the latest saved arrangement.

## 2026-09-25 — Kerf-only clearance, safe cutting area and order deletion

- Decision: remove independent piece spacing from Otimizar. Derive the minimum separation between compensated cut paths from the selected kerf and evaluate real contours, not bounding boxes. Keep sheet margin as the operator-controlled safe cutting area, including CNC lead paths. Permit deletion before production, reversing exported inventory only when the generated remnant has not been used; preserve stock movements and audit history.
- Context: the user rejected the separate spacing control, requested tighter sheet utilization and asked for an order deletion option.
- Rationale: the independent bounding-box gap prevented irregular pieces from interlocking. Kerf supplies the physical separation rule; the safe margin protects the sheet edge. Stock reversal must not recreate material already consumed by another order. This supersedes the independent-clearance part of the earlier preview safety decision.

## 2026-09-27 — Physical clearance and technical DXF

- Decision: store mandatory thermal clearance and lead-in per material thickness, with thermal clearance strictly larger than kerf. Apply that clearance between cut paths and against the actual sheet edge. Store optional tabs and gap length per order. Both CNC and a supplementary technical DXF use one pierce/lead/cut sequence; CNC remains the required machine output. The DXF exposes pierce, lead and uncut tab layers for review.
- Context: the user explicitly added physical laser-cut requirements that supersede the earlier kerf-only clearance decision and requested a DXF generation pipeline after previously choosing CNC for the machine.
- Rationale: kerf compensation and thermal protection serve different purposes. Preserving CNC as the machine file respects the supplied controller format; the DXF makes the physical plan inspectable. Existing orders keep their historical output until deliberately edited or reorganized.

## 2026-09-28 — Three-millimetre clearance for 1 mm carbon steel

- Decision: use 3 mm as the minimum distance between compensated cut contours and between contours and sheet edge for the local 1 mm carbon-steel profile. Keep kerf as a separate, unchanged machine measurement. Allow a ready order to be reorganized under a stricter clearance even when its reusable remnant shrinks; the operator then saves the preview to regenerate CNC/DXF and synchronize the remnant.
- Context: the user identified pieces nearly touching and others too far apart in Pedidos #8/#9, then explicitly chose 3 mm as the minimum between piece contours, distinct from kerf.
- Rationale: the earlier 0.6 mm profile was an uncalibrated estimate. Safety recalibration must take precedence over a historical remnant-size comparison. Other material profiles keep their own settings.

## 2026-09-29 — Benchmark material use across the whole batch

- Decision: assess optimization on all physical sheets of a batch, counting both full sheets required and sheet area net of reusable rectangular remnants. Use a 12% reduction against the current two-sheet, 3,000 × 1,200 mm arrangement of Pedidos #8/#9 as a comparison target, while preserving the 3 mm cutting clearance. Do not accept a proposal solely because it places more pieces on the first sheet.
- Context: the user reported a competitor's 12% saving on the same sheet size and asked to evaluate the two existing sheets together.
- Rationale: a first-sheet proposal with 25 rather than 20 pieces left larger parts on the second sheet and increased total net consumption; single-sheet placement count is not a sufficient material metric. The 12% figure is a target, not a verified gain from the reference image.

## 2026-09-29 — One order per batch and manual CNC baseline

- Decision: export a batch as one order, with additional sheets stored internally in `pedido_chapas` and separate per-sheet files, stock movements and remnants. The first sheet stays in the existing order columns for single-sheet compatibility. This supersedes the 2026-09-24 decision to create one order per sheet.
- Context: the user explicitly asked to avoid duplicate orders for a multi-sheet arrangement, clear local catalog/order/sheet data, and compare the four DXFs (10/10/10/5) with the supplied original CNC. The user confirmed two new source sheets of 1,500 × 3,000 mm after the CNC extents contradicted the previously stated 3,000 × 1,200 mm format.
- Decision: use 3 mm thermal clearance for this benchmark and infer a nominal 2 mm kerf from the CNC's rectangle path (251.5 × 130 mm nominal DXF, 253.5 × 132 mm cut path). External kerf offset is outward, holes inward. This supersedes the earlier negative external-offset convention; physical machine calibration still remains required.
- Rationale: one order matches the managerial unit the operator requested. Using the CNC's actual sheet format and cut-centerline offset makes the material comparison reproducible, while an unverified machine setting must not be described as calibrated.

## 2026-09-30 — Minimum 10 mm cutting clearance for all materials

- Decision: every material/thickness profile must use at least 10 mm between compensated cut contours and from the cut path to the sheet edge. This supersedes the earlier 3 mm benchmark rule. Kerf remains a separate parameter; existing orders are replanned deliberately rather than silently altering their saved CNC.
- Context: the user reported that the saved arrangement looked unchanged and explicitly confirmed that the 10 mm minimum applies to all materials and thicknesses.
- Rationale: a persisted arrangement does not change when a profile is edited. Increasing the safety clearance can require more sheets, so the complete batch and its stock/CNC must be revalidated together.

## 2026-10-05 — Outbound PC worker for the shared-host test site

- Decision: run the nesting and Python operations on the owner's Windows PC through an outbound SFTP file queue while retaining the Laravel site on UNI5. Start the worker at Windows sign-in. Keep requests, responses, DXFs and generated outputs in private storage; do not store FTP credentials in project files or expose the queue through the web root.
- Context: UNI5 stopped the remote Node process, disabled PHP `proc_open`, and refused reverse SSH forwarding. The resulting connection refusal made valid DXF previews show a generic file-error message. The user had already requested a PC-dependent test site.
- Rationale: the outbound worker does not require inbound ports or a persistent process on the shared host. The site must report PC disconnection clearly. This deployment is suitable for attended client testing, not autonomous service while the PC is off or signed out.

## 2026-10-08 — Run the PC optimizer only on explicit request

- Decision: leave the Windows `NestLaserPcWorker` scheduled task disabled and the local Node/Python optimizer stopped. Start the worker only when the user explicitly asks in this chat, then stop it after the requested session. This supersedes the automatic sign-in and minute-by-minute restart decision from 2026-10-05.
- Context: the user does not want a command process running on the PC all day.
- Rationale: on-demand operation avoids continuous local resource use. The remote site remains accessible, but preview, optimization and generated outputs that require the worker are unavailable while it is off.

## 2026-10-09 — Manual session for client testing

- Decision: use an enabled Windows task with no triggers (`NestLaserPcWorkerManual`) for the explicitly requested testing session, and remove the old scheduled task with automatic triggers. Start the manual task on request and stop it when the user ends the testing session; never restore periodic or login startup implicitly.
- Context: the user asked to run the service so other people can access the system, while preserving the prior preference against all-day automatic operation.
- Rationale: an on-demand scheduled task keeps the worker independent of a terminal window while avoiding an automatic launch after sign-in or at a fixed interval.
