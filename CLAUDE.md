# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Commands

```bash
# First-time setup (installs deps, creates .env, runs migrations, builds frontend)
composer run setup

# Development (runs Laravel on :8000 + Vite HMR on :5173 concurrently)
composer run dev

# Run all tests
composer run test
# or
php artisan test

# Run a specific test
php artisan test --filter FtthIntelligenceTest

# Full quality gate before committing (Pint style check + JS syntax check + all tests)
composer run check

# Lint PHP (Laravel Pint)
./vendor/bin/pint

# Syntax-check the hand-written map JS (public/js/**, no build step)
npm run check:js

# Browser smoke test for the map (needs `composer run dev` running; opt-in, not in `check`)
npm run test:e2e

# Production build
npm run build && php artisan optimize
```

A pre-commit hook that runs `composer run check` lives in `.githooks/`. Enable it once per clone with `git config core.hooksPath .githooks` (bypass a single commit with `git commit --no-verify`).

## Architecture

### Overview

FTTH Manager is a single-user Laravel 13 + SQLite desktop-class web app for designing passive optical FTTH networks. There is no authentication. The entry point is `/mapa` — everything else (reports, settings, project check) is secondary.

### Map flow (most critical path)

The map at `/mapa` is a Leaflet.js canvas. `MapController::map()` loads all network elements and serialises them into `window.ftthMapConfig` (a JSON blob injected into the Blade view). The JS modules under `public/js/map/` (see "Frontend JS" below) read from this config and make REST calls back to the API.

**Two-phase persistence:**
1. **Draft** (`POST /mapa/draft`) — auto-saves drawing state to `map_drafts` every 700 ms while the user is drawing. Restores on next page load.
2. **Plan** (`POST /mapa/plan`) — commits the full drawn plan to real DB tables (ODFs, cabinets, houses, routes, appendix items) and deletes the draft.

### Controllers and shared logic

All resource controllers (`MapController`, `RouteController`, `CabinetController`, etc.) use the `ManagesFtthData` trait (`app/Http/Controllers/Concerns/ManagesFtthData.php`). This trait contains:
- Shared validation helpers (`latitudeRules`, `longitudeRules`, `ensureBelongsToProject`)
- Geometry math: haversine distance, point-on-segment projection, path slicing (`projectPointToRoute`, `routePathBetween`, `compactPath`)
- DXF polyline parsing (`parseDxfPolylines`)
- Branch/route auto-creation and sync logic (`createBranchForRoute`, `syncBranchForRoute`, `deleteRouteWithBranch`)
- Drop path smart routing (`dropPathForHouse` — snaps drop to nearest secondary route within 35 m)

### FtthIntelligenceService

`app/Services/FtthIntelligenceService.php` is the planning brain:
- `previewOdoPlan()` — groups unassigned houses by secondary branch proximity, proposes ODO cabinet positions (medoid of each group projected onto the route), returns a JSON preview
- `confirmOdoPlan()` — persists the confirmed preview; validates no cross-project contamination, no duplicate house assignments, max 12 houses per ODO
- `validateProject()` — returns a flat list of `{level, message, element_type, element_id, recommendation}` items covering ODF capacity, cabinet splitter config, missing drop routes, house distance from branch, etc.
- `materialSummary()` — aggregates cable lengths by type/diameter with a configurable reserve %

### Data model relationships

```
Project → ODFs → (cabinets via odf_id)
Project → Cabinets → Houses
Project → NetworkRoutes (table: routes)
Project → NetworkBranches (1:1 with non-drop, non-trench routes via route_id)
Project → MapDrafts (at most 1 per project)
Project → Materials
Project → ProjectAppendixItems (manholes, FI 130 borings)
```

Key model notes:
- `NetworkRoute` uses table name `routes`, not `network_routes`
- `path` column stores geometry as JSON array of `[lat, lng]` pairs (cast to array)
- `Cabinet.capacity` is a computed attribute: `splitter_count × ports_per_splitter` (max 3×4 = 12)
- `Cabinet.parent_cabinet_id` allows cascaded ODO trees (secondary ODOs fed from another ODO rather than ODF)
- `NetworkBranch` mirrors its linked route's name/type and is auto-created/deleted when a route is saved/deleted

### Route types

| `route_type` | Meaning |
|---|---|
| `trench` | Physical trench — no microduct/fiber, rendered under other layers |
| `backbone` | ODF backbone |
| `feeder` | ODF → ODO (primary) |
| `distribution` | ODO → ODO (secondary) |
| `drop` | ODO → house |

`trench` and `drop` routes do not create a `NetworkBranch`.

### Frontend JS

The map's JS lives in `public/js/map/*.js` — plain (non-transpiled) scripts loaded directly, in order, by `resources/views/ftth/map.blade.php` (`state.js`, `utils.js`, `layers.js`, `markers.js`, `context.js`, `routes.js`, `connect.js`, `edit.js`, `draw.js`, `autoplan.js`, `draft.js`, `toolbar.js`, `init.js`). They are **not** part of the Vite pipeline — edit them directly, no build step needed. `init.js` bootstraps the map and holds most event-listener wiring; `state.js` declares shared globals (`data`, `map`, `layerRegistry`, undo stacks, etc.) that the other modules rely on, so it must load first. `public/js/ftth-dxf-layer.js` handles DXF background layer rendering via canvas (DWG is not supported — must be saved as DXF first).

DXF background layers are stored in the **browser's IndexedDB**, not on the server. `MapLayerController::upload()` only handles coordinate-system detection and passes the result back for the browser to store.

### Frontend CSS/JS build (Vite)

Vite only processes `resources/css/app.css` (Tailwind v4) and `resources/js/app.js`. The compiled output goes to `public/build/`. All UI pages outside the map use this compiled CSS.

### Testing

Tests use in-memory SQLite (`DB_DATABASE=:memory:`). Feature tests cover `FtthIntelligenceService` planning logic and the Mediasky workflow. Run `php artisan test --filter ClassName` to target a single class.
