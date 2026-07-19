<?php
/**
 * map.php — Interactive GIS map for browsing radar sounder ground tracks.
 *
 * Displays an OpenLayers-based planetary map with instrument layers for
 * SHARAD, MARSIS (Mars and Phobos), and LRS (Moon). Basemaps and instrument
 * configurations are loaded from the FastAPI backend (/gis/api/config/*), and
 * vector features are fetched per-viewport from /gis/api/vectors/*.
 *
 * Requires authentication. The FastAPI backend is proxied through Apache
 * so all /gis/api/* requests are relative.
 */

require_once __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/layout.php';

require_login();

// Extra <head> content for OpenLayers and the map styles
$head_extra = <<<'HEAD'
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/ol@9.2.4/dist/ol.css">
    <style>
    /* ===================================================================
       Map page layout — fill viewport between navbar and footer
       =================================================================== */
    body.page-map {
      display: flex;
      flex-direction: column;
      min-height: 100vh;
      overflow: hidden;
    }
    body.page-map main.container-fluid {
      flex: 1;
      padding: 0 !important;
      position: relative;
      overflow: hidden;
      display: flex;
      flex-direction: column;
    }
    body.page-map footer {
      display: none;
    }

    /* ===================================================================
       Map container
       =================================================================== */
    #porpass-map {
      position: absolute;
      top: 0; left: 0; right: 0; bottom: 0;
      background: #111;
    }

    /* ===================================================================
       GIS controls — all scoped under #porpass-map-wrap to avoid
       conflicts with Bootstrap / porpass.css
       =================================================================== */
    #porpass-map-wrap {
      flex: 1;
      position: relative;
      font-family: 'DM Sans', sans-serif;
    }

    /* Title box — top left */
    #gis-title-box {
      position: absolute;
      top: 10px; left: 10px;
      background: rgba(0, 0, 0, 0.75);
      color: #eee;
      border-radius: 12px;
      padding: 10px 14px;
      max-width: 260px;
      z-index: 10;
      box-shadow: 0 2px 8px rgba(0,0,0,0.4);
      font-family: 'DM Serif Display', serif;
    }
    #gis-title-header {
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      gap: 8px;
    }
    .gis-title-name {
      font-size: 18px;
      font-weight: 500;
      line-height: 1.2;
      color: #5DCAA5;
      letter-spacing: 0.06em;
    }
    #gis-info-btn {
      background: none;
      border: 1px solid #555;
      border-radius: 50%;
      color: #888;
      font-size: 10px;
      width: 18px; height: 18px;
      cursor: pointer;
      padding: 0;
      line-height: 18px;
      text-align: center;
      flex-shrink: 0;
      margin-top: 2px;
      font-family: 'DM Sans', sans-serif;
    }
    #gis-info-btn:hover { border-color: #aaa; color: #eee; }
    .gis-title-sub {
      font-size: 11px;
      font-weight: normal;
      color: #aaa;
      margin-top: 5px;
      line-height: 1.45;
    }

    /* Control panel — top right */
    #gis-controls {
      position: absolute;
      top: 10px; right: 10px;
      background: rgba(0, 0, 0, 0.65);
      color: #eee;
      padding: 10px 14px;
      border-radius: 12px;
      font-size: 13px;
      z-index: 10;
      width: 220px;
      box-shadow: 0 2px 8px rgba(0,0,0,0.4);
    }
    #porpass-map-wrap .ctrl-row {
      display: flex;
      align-items: center;
      gap: 8px;
      margin-bottom: 8px;
    }
    #porpass-map-wrap .ctrl-row:last-child { margin-bottom: 0; }
    #porpass-map-wrap .ctrl-sep { border: none; border-top: 1px solid #2e2e2e; margin: 8px 0; }
    #porpass-map-wrap .ctrl-label {
      white-space: nowrap;
      color: #888;
      font-size: 10px;
      text-transform: uppercase;
      letter-spacing: 0.06em;
      min-width: 54px;
    }
    #porpass-map-wrap select {
      flex: 1;
      width: 100%;
      box-sizing: border-box;
      background: #2a2a2a;
      color: #ddd;
      border: 1px solid #555;
      border-radius: 3px;
      padding: 3px 6px;
      font-size: 12px;
      cursor: pointer;
      font-family: 'DM Sans', sans-serif;
    }
    #porpass-map-wrap select:focus { outline: none; border-color: #888; }

    /* Layer toggle */
    #porpass-map-wrap .layer-toggle {
      display: flex;
      align-items: center;
      gap: 7px;
      cursor: pointer;
      color: #ccc;
      font-size: 12px;
      user-select: none;
      width: 100%;
    }
    #porpass-map-wrap .layer-toggle input[type="checkbox"] {
      accent-color: #ff8c00;
      width: 14px; height: 14px;
      cursor: pointer;
      flex-shrink: 0;
    }
    #porpass-map-wrap .layer-toggle.pending {
      color: #555;
      cursor: default;
      font-style: italic;
    }
    #porpass-map-wrap .layer-toggle.pending input[type="checkbox"] {
      cursor: default;
    }

    /* Filter panel */
    #porpass-map-wrap .filter-row {
      display: flex;
      align-items: center;
      gap: 6px;
      margin-bottom: 6px;
      font-size: 11px;
    }
    #porpass-map-wrap .f-label {
      color: #999;
      min-width: 72px;
      flex-shrink: 0;
    }
    #porpass-map-wrap .f-range {
      display: flex;
      align-items: center;
      gap: 4px;
      flex: 1;
    }
    #porpass-map-wrap input[type="number"] {
      width: 52px;
      background: #2a2a2a;
      color: #ddd;
      border: 1px solid #555;
      border-radius: 3px;
      padding: 3px 5px;
      font-size: 11px;
      font-family: 'DM Sans', sans-serif;
      -moz-appearance: textfield;
    }
    #porpass-map-wrap input[type="number"]::-webkit-inner-spin-button { opacity: 0.4; }
    #porpass-map-wrap input[type="number"]:focus { outline: none; border-color: #888; }
    #porpass-map-wrap .f-input-full { width: 100%; box-sizing: border-box; }
    #porpass-map-wrap .f-dash { color: #555; }
    #porpass-map-wrap .filter-buttons {
      display: flex;
      gap: 6px;
      margin-top: 8px;
    }
    #porpass-map-wrap .f-btn {
      flex: 1;
      padding: 5px 0;
      border: none;
      border-radius: 3px;
      cursor: pointer;
      font-size: 11px;
      font-weight: 600;
      font-family: 'DM Sans', sans-serif;
    }
    #porpass-map-wrap .f-btn-apply { background: #c96d00; color: #fff; }
    #porpass-map-wrap .f-btn-apply:hover:not(:disabled) { background: #ff8c00; }
    #porpass-map-wrap .f-btn-apply:disabled { background: #555; color: #888; cursor: not-allowed; }
    #porpass-map-wrap .f-btn-reset { background: #2a2a2a; color: #999; border: 1px solid #444; }
    #porpass-map-wrap .f-btn-reset:hover { background: #383838; color: #ccc; }
    #porpass-map-wrap .f-count {
      font-size: 11px;
      color: #888;
      text-align: center;
      margin-top: 7px;
      min-height: 15px;
    }

    /* Shared bounding box filter */
    #porpass-map-wrap .bbox-section {
      display: none;
      margin-top: 4px;
    }
    #porpass-map-wrap .bbox-heading {
      font-size: 10px;
      text-transform: uppercase;
      letter-spacing: 0.06em;
      color: #888;
      margin-bottom: 6px;
    }
    #porpass-map-wrap .bbox-grid {
      display: grid;
      grid-template-columns: auto 1fr auto 1fr;
      gap: 4px 6px;
      align-items: center;
      font-size: 11px;
    }
    #porpass-map-wrap .bbox-grid label {
      color: #999;
      font-size: 10px;
      white-space: nowrap;
    }
    #porpass-map-wrap .bbox-grid input[type="number"] {
      width: 100%;
      box-sizing: border-box;
    }

    /* Browse Details link */
    #porpass-map-wrap .browse-link {
      display: block;
      text-align: center;
      margin-top: 8px;
      padding: 5px 0;
      border: 1px solid #555;
      border-radius: 3px;
      font-size: 11px;
      font-weight: 600;
      color: #999;
      text-decoration: none;
      cursor: default;
      pointer-events: none;
      font-family: 'DM Sans', sans-serif;
    }
    #porpass-map-wrap .browse-link.active {
      color: #ff8c00;
      border-color: #c96d00;
      cursor: pointer;
      pointer-events: auto;
    }
    #porpass-map-wrap .browse-link.active:hover {
      background: rgba(201, 109, 0, 0.15);
      color: #ffaa33;
    }

    /* Planet toggle */
    #porpass-map-wrap .planet-toggle {
      display: flex;
      flex: 1;
      border: 1px solid #555;
      border-radius: 3px;
      overflow: hidden;
    }
    #porpass-map-wrap .planet-btn {
      flex: 1;
      background: #2a2a2a;
      color: #aaa;
      border: none;
      border-right: 1px solid #555;
      padding: 5px 0;
      font-size: 11px;
      cursor: pointer;
      font-weight: 600;
      letter-spacing: 0.04em;
      font-family: 'DM Sans', sans-serif;
    }
    #porpass-map-wrap .planet-btn:last-child { border-right: none; }
    #porpass-map-wrap .planet-btn.active     { background: #c96d00; color: #fff; }
    #porpass-map-wrap .planet-btn:hover:not(.active) { background: #383838; color: #ccc; }

    /* Projection toggle */
    #porpass-map-wrap .proj-toggle {
      display: flex;
      flex: 1;
      border: 1px solid #555;
      border-radius: 3px;
      overflow: hidden;
    }
    #porpass-map-wrap .proj-btn {
      flex: 1;
      background: #2a2a2a;
      color: #aaa;
      border: none;
      border-right: 1px solid #555;
      padding: 4px 0;
      font-size: 10px;
      cursor: pointer;
      font-weight: 500;
      font-family: 'DM Sans', sans-serif;
    }
    #porpass-map-wrap .proj-btn:last-child { border-right: none; }
    #porpass-map-wrap .proj-btn.active     { background: #c96d00; color: #fff; }
    #porpass-map-wrap .proj-btn:hover:not(.active) { background: #383838; color: #ccc; }

    /* Feature click popup */
    #gis-popup {
      position: absolute;
      background: rgba(0, 0, 0, 0.92);
      color: #eee;
      border: 1px solid #333;
      border-radius: 12px;
      padding: 10px 12px 10px 12px;
      font-size: 12px;
      min-width: 210px;
      max-width: 290px;
      box-shadow: 0 2px 10px rgba(0,0,0,0.6);
      z-index: 20;
      font-family: 'DM Sans', sans-serif;
    }
    #gis-popup-closer {
      position: absolute;
      top: 6px; right: 9px;
      cursor: pointer;
      color: #777;
      font-size: 13px;
      line-height: 1;
      user-select: none;
    }
    #gis-popup-closer:hover { color: #fff; }
    .gis-popup-title {
      font-size: 11px;
      text-transform: uppercase;
      letter-spacing: 0.06em;
      color: #888;
      margin-bottom: 7px;
      padding-right: 16px;
    }
    #porpass-map-wrap .popup-table { width: 100%; border-collapse: collapse; }
    #porpass-map-wrap .popup-table td {
      padding: 2px 4px;
      vertical-align: top;
      font-size: 11px;
    }
    #porpass-map-wrap .popup-table td:first-child {
      color: #888;
      white-space: nowrap;
      padding-right: 10px;
      width: 1%;
    }
    #porpass-map-wrap .popup-table a { color: #ff8c00; text-decoration: none; }
    #porpass-map-wrap .popup-table a:hover { text-decoration: underline; }

    /* Browse product thumbnail */
    .gis-popup-browse {
      margin-top: 8px;
      text-align: center;
    }
    .gis-popup-browse a {
      display: inline-block;
    }
    .gis-popup-browse img {
      max-width: 100%;
      max-height: 80px;
      border: 1px solid #444;
      border-radius: 4px;
      cursor: pointer;
      transition: border-color 0.15s;
    }
    .gis-popup-browse img:hover {
      border-color: #ff8c00;
    }
    .gis-popup-browse .browse-label {
      display: block;
      font-size: 9px;
      color: #666;
      margin-top: 3px;
      text-transform: uppercase;
      letter-spacing: 0.04em;
    }

    /* Bottom left: logo + mouse position */
    #gis-bottom-left {
      position: absolute;
      bottom: 10px; left: 10px;
      display: flex;
      align-items: center;
      gap: 8px;
      z-index: 10;
    }
    #gis-logo {
      width: 40px; height: 40px;
      border-radius: 50%;
      object-fit: cover;
      flex-shrink: 0;
    }
    #gis-mouse-position {
      background: rgba(0, 0, 0, 0.65);
      color: #eee;
      font: 12px monospace;
      padding: 4px 10px;
      border-radius: 12px;
      pointer-events: none;
      white-space: nowrap;
    }

    /* Zoom controls — bottom right */
    #gis-zoom-controls {
      position: absolute;
      bottom: 10px; right: 10px;
      display: flex;
      flex-direction: column;
      gap: 2px;
      background: rgba(0, 0, 0, 0.65);
      border-radius: 12px;
      padding: 5px;
      z-index: 10;
      box-shadow: 0 2px 8px rgba(0,0,0,0.4);
    }
    #gis-zoom-controls button {
      width: 30px; height: 30px;
      background: transparent;
      border: none;
      color: #fff;
      font-size: 20px;
      font-weight: 400;
      line-height: 30px;
      text-align: center;
      cursor: pointer;
      border-radius: 8px;
      font-family: 'DM Sans', sans-serif;
      padding: 0;
    }
    #gis-zoom-controls button:hover { background: rgba(255, 255, 255, 0.12); }
    .ol-attribution { display: none !important; }

    /* Info modal */
    #gis-info-overlay {
      position: fixed;
      top: 0; left: 0; right: 0; bottom: 0;
      background: rgba(0, 0, 0, 0.70);
      z-index: 100;
      display: none;
      align-items: center;
      justify-content: center;
    }
    #gis-info-modal {
      background: rgba(12, 12, 12, 0.97);
      border: 1px solid #2e2e2e;
      border-radius: 12px;
      padding: 20px 24px;
      max-width: 420px;
      width: 90%;
      max-height: 80vh;
      overflow-y: auto;
      color: #eee;
      position: relative;
      box-shadow: 0 4px 24px rgba(0,0,0,0.8);
      font-family: 'DM Sans', sans-serif;
    }
    #gis-info-close {
      position: absolute;
      top: 10px; right: 14px;
      background: none;
      border: none;
      color: #666;
      font-size: 16px;
      cursor: pointer;
      padding: 0;
      line-height: 1;
    }
    #gis-info-close:hover { color: #eee; }
    #porpass-map-wrap .modal-section { margin-bottom: 16px; }
    #porpass-map-wrap .modal-section:last-child { margin-bottom: 0; }
    #porpass-map-wrap .modal-heading {
      font-size: 10px;
      text-transform: uppercase;
      letter-spacing: 0.08em;
      color: #888;
      margin-bottom: 6px;
      font-weight: 600;
    }
    #porpass-map-wrap .modal-text {
      font-size: 12px;
      color: #ccc;
      line-height: 1.55;
      margin: 0 0 6px 0;
    }
    #porpass-map-wrap .modal-text:last-child { margin-bottom: 0; }
    #porpass-map-wrap .modal-text strong { color: #eee; }
    </style>
HEAD;

open_layout('Map', $head_extra, 'page-map');
?>

<!-- ================================================================
     Map wrapper — all GIS elements are children of this container
     ================================================================ -->
<div id="porpass-map-wrap">

  <div id="porpass-map"></div>

  <!-- Title box — top left -->
  <div id="gis-title-box">
    <div id="gis-title-header">
      <div style="display: flex; align-items: center; gap: 8px;">
        <svg width="36" height="20" viewBox="0 0 44 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
          <polyline points="0,12 6,12 9,4 13,20 17,8 21,14 24,12 30,12"
            stroke="#5DCAA5" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" fill="none"/>
          <polyline points="4,15 8,15 10,19 14,11 18,16 22,13 25,15 30,15"
            stroke="#EF9F27" stroke-width="1.1" stroke-linecap="round" stroke-linejoin="round" opacity="0.85" fill="none"/>
        </svg>
        <span class="gis-title-name">PORPASS GIS</span>
      </div>
      <button id="gis-info-btn" title="About">ⓘ</button>
    </div>
    <div class="gis-title-sub">The Planetary Orbital Radar Processing and Simulation System Geographic Information System</div>
  </div>

  <!-- Control panel — top right -->
  <div id="gis-controls">

    <!-- Planet switcher -->
    <div class="ctrl-row">
      <div id="planet-toggle" class="planet-toggle">
        <button class="planet-btn active" data-planet="mars">Mars</button>
        <button class="planet-btn"        data-planet="moon">Moon</button>
        <button class="planet-btn"        data-planet="phobos">Phobos</button>
      </div>
    </div>

    <hr class="ctrl-sep">

    <!-- Basemap selector -->
    <div class="ctrl-row">
      <span class="ctrl-label">Basemap</span>
      <select id="basemap-select"><option>Loading…</option></select>
    </div>

    <!-- Projection toggle -->
    <div id="proj-row" class="ctrl-row">
      <span class="ctrl-label">View</span>
      <div id="proj-toggle" class="proj-toggle">
        <button class="proj-btn active" data-proj="cylindrical">Cylindrical</button>
        <button class="proj-btn"        data-proj="north_polar">N Polar</button>
        <button class="proj-btn"        data-proj="south_polar">S Polar</button>
      </div>
    </div>

    <hr class="ctrl-sep">

    <!-- Instrument layer toggles — injected dynamically -->
    <div id="instrument-layers"></div>

    <!-- Shared bounding box filter — visible when any instrument is enabled -->
    <div id="gis-bbox-section" class="bbox-section">
      <hr class="ctrl-sep">
      <div class="bbox-heading">Bounding Box (overrides viewport)</div>
      <div class="bbox-grid">
        <label for="gis-bbox-min-lat">Min Lat</label>
        <input type="number" id="gis-bbox-min-lat" min="-90" max="90" step="0.01" placeholder="-90">
        <label for="gis-bbox-max-lat">Max Lat</label>
        <input type="number" id="gis-bbox-max-lat" min="-90" max="90" step="0.01" placeholder="90">
        <label for="gis-bbox-min-lon">Min Lon</label>
        <input type="number" id="gis-bbox-min-lon" min="-180" max="180" step="0.01" placeholder="-180">
        <label for="gis-bbox-max-lon">Max Lon</label>
        <input type="number" id="gis-bbox-max-lon" min="-180" max="180" step="0.01" placeholder="180">
      </div>
    </div>

    <!-- Browse Details link — greyed out until filters are applied -->
    <a id="gis-browse-link" class="browse-link" href="#">Browse Details</a>

  </div>

  <!-- Bottom left: institute logo + mouse position -->
  <div id="gis-bottom-left">
    <img id="gis-logo" src="/resources/img/logo.png" alt="PORPASS">
    <div id="gis-mouse-position">—</div>
  </div>

  <!-- Feature click popup (managed as an ol.Overlay) -->
  <div id="gis-popup" style="display:none">
    <span id="gis-popup-closer">✕</span>
    <div class="gis-popup-title" id="gis-popup-title"></div>
    <div id="gis-popup-content"></div>
  </div>

  <!-- Zoom controls — bottom right -->
  <div id="gis-zoom-controls">
    <button id="gis-zoom-in">+</button>
    <button id="gis-zoom-out">−</button>
  </div>

  <!-- Info modal -->
  <div id="gis-info-overlay">
    <div id="gis-info-modal">
      <button id="gis-info-close">✕</button>

      <div class="modal-section">
        <div class="modal-heading">About PORPASS GIS</div>
        <p class="modal-text">PORPASS GIS provides interactive map access to orbital radar sounder ground track coverage for planetary science missions.</p>
      </div>

      <div class="modal-section">
        <div class="modal-heading">Instruments</div>
        <p class="modal-text"><strong>SHARAD</strong> — SHAllow RADar aboard NASA's Mars Reconnaissance Orbiter. Provided by ASI.</p>
        <p class="modal-text"><strong>MARSIS</strong> — Mars Advanced Radar for Subsurface and Ionosphere Sounding aboard ESA's Mars Express.</p>
        <p class="modal-text"><strong>LRS</strong> — Lunar Radar Sounder aboard JAXA's Kaguya/SELENE lunar orbiter.</p>
      </div>

      <div class="modal-section">
        <div class="modal-heading">Data Sources</div>
        <p class="modal-text">Basemap tiles: USGS Astrogeology Science Center<br>Radar coverage: NASA Planetary Data System (PDS)</p>
      </div>

      <div class="modal-section">
        <div class="modal-heading">Funding</div>
        <p class="modal-text">Development funded by the NASA Planetary Data Archival, Restoration, and Tools (PDART) Program, grant number 80NSSC20K1057.</p>
      </div>

      <div class="modal-section">
        <div class="modal-heading">Technical</div>
        <p class="modal-text">Built with OpenLayers, FastAPI, MariaDB.</p>
      </div>
    </div>
  </div>

</div><!-- /porpass-map-wrap -->

<!-- proj4js must load before OpenLayers -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/proj4js/2.11.0/proj4.js"></script>
<script src="https://cdn.jsdelivr.net/npm/ol@9.2.4/dist/ol.js"></script>

<script>
  // =========================================================================
  // PORPASS GIS — OpenLayers map (integrated into PHP layout)
  //
  // Element IDs have been prefixed with "gis-" where needed to avoid
  // collisions with Bootstrap / PORPASS classes. Internal IDs used only
  // by the instrument/filter system (inst-row-*, inst-toggle-*, etc.)
  // are unchanged since they are generated dynamically.
  // =========================================================================

  // -----------------------------------------------------------------------
  // 1. CRS registration — Mars + Moon + Phobos, cylindrical and polar
  // -----------------------------------------------------------------------
  proj4.defs('IAU:49900',
    '+proj=eqc +lat_ts=0 +lon_0=0 +a=3396190 +b=3376200 +units=m +no_defs');
  proj4.defs('IAU:49918',
    '+proj=stere +lat_0=90 +lon_0=0 +k=1 +a=3396190 +b=3376200 +units=m +no_defs');
  proj4.defs('IAU:49920',
    '+proj=stere +lat_0=-90 +lon_0=0 +k=1 +a=3396190 +b=3376200 +units=m +no_defs');
  proj4.defs('IAU:30100',
    '+proj=eqc +lat_ts=0 +lon_0=0 +a=1737400 +b=1737400 +units=m +no_defs');
  proj4.defs('IAU:30118',
    '+proj=stere +lat_0=90 +lon_0=0 +k=1 +a=1737400 +b=1737400 +units=m +no_defs');
  proj4.defs('IAU:30120',
    '+proj=stere +lat_0=-90 +lon_0=0 +k=1 +a=1737400 +b=1737400 +units=m +no_defs');
  proj4.defs('IAU:40100',
    '+proj=eqc +lat_ts=0 +lon_0=0 +a=11080 +b=11080 +units=m +no_defs');
  proj4.defs('EPSG:32661',
    '+proj=stere +lat_0=90 +lon_0=0 +k=1 +a=3396190 +b=3376200 +units=m +no_defs');
  proj4.defs('EPSG:32761',
    '+proj=stere +lat_0=-90 +lon_0=0 +k=1 +a=3396190 +b=3376200 +units=m +no_defs');
  ol.proj.proj4.register(proj4);

  // Extents
  const MARS_A            = 3396190;
  const MOON_A            = 1737400;
  const PHOBOS_A          = 11080;
  const DEG2RAD           = Math.PI / 180;
  const RAD2DEG           = 180 / Math.PI;

  const PHOBOS_EXTENT         = [-34809,    -17404,   34809,    17404];
  const MARS_EXTENT           = [-10669320, -5334660, 10669320, 5334660];
  const MARS_POLAR_EXTENT     = [-5000000,  -5000000, 5000000,  5000000];
  const MARS_WMS_POLAR_EXTENT = [-2357032,  -2357032, 2357032,  2357032];
  const MOON_EXTENT           = [-5458510,  -2729255, 5458510,  2729255];
  const MOON_POLAR_EXTENT     = [-2000000,  -2000000, 2000000,  2000000];
  const MOON_WMS_POLAR_EXTENT = [-931067,   -931067,  931067,   931067];

  ol.proj.get('IAU:40100').setExtent(PHOBOS_EXTENT);
  ol.proj.get('IAU:40100').setWorldExtent([-180, -90, 180, 90]);
  ol.proj.get('IAU:49900').setExtent(MARS_EXTENT);
  ol.proj.get('IAU:49900').setWorldExtent([-180, -90, 180, 90]);
  ol.proj.get('IAU:49918').setExtent(MARS_POLAR_EXTENT);
  ol.proj.get('IAU:49920').setExtent(MARS_POLAR_EXTENT);
  ol.proj.get('EPSG:32661').setExtent(MARS_WMS_POLAR_EXTENT);
  ol.proj.get('EPSG:32761').setExtent(MARS_WMS_POLAR_EXTENT);
  ol.proj.get('IAU:30100').setExtent(MOON_EXTENT);
  ol.proj.get('IAU:30100').setWorldExtent([-180, -90, 180, 90]);
  ol.proj.get('IAU:30118').setExtent(MOON_POLAR_EXTENT);
  ol.proj.get('IAU:30120').setExtent(MOON_POLAR_EXTENT);

  // Custom transforms
  ol.proj.addCoordinateTransforms('EPSG:4326', 'IAU:49900',
    function (c) { return [c[0] * DEG2RAD * MARS_A, c[1] * DEG2RAD * MARS_A]; },
    function (c) { return [c[0] / MARS_A * RAD2DEG, c[1] / MARS_A * RAD2DEG]; }
  );
  ol.proj.addCoordinateTransforms('EPSG:4326', 'IAU:30100',
    function (c) { return [c[0] * DEG2RAD * MOON_A, c[1] * DEG2RAD * MOON_A]; },
    function (c) { return [c[0] / MOON_A * RAD2DEG, c[1] / MOON_A * RAD2DEG]; }
  );
  ol.proj.addCoordinateTransforms('EPSG:4326', 'IAU:40100',
    function (c) { return [c[0] * DEG2RAD * PHOBOS_A, c[1] * DEG2RAD * PHOBOS_A]; },
    function (c) { return [c[0] / PHOBOS_A * RAD2DEG, c[1] / PHOBOS_A * RAD2DEG]; }
  );

  function setPolarWmsCrs(a, b, wmsExtent) {
    proj4.defs('EPSG:32661',
      '+proj=stere +lat_0=90 +lon_0=0 +k=1 +a=' + a + ' +b=' + b + ' +units=m +no_defs');
    proj4.defs('EPSG:32761',
      '+proj=stere +lat_0=-90 +lon_0=0 +k=1 +a=' + a + ' +b=' + b + ' +units=m +no_defs');
    ol.proj.proj4.register(proj4);
    ol.proj.get('EPSG:32661').setExtent(wmsExtent);
    ol.proj.get('EPSG:32761').setExtent(wmsExtent);
  }

  // -----------------------------------------------------------------------
  // 2. Projection config
  // -----------------------------------------------------------------------
  var currentPlanet = 'mars';
  var currentProj   = 'cylindrical';

  var BODY_PROJ_CFG = {
    mars: {
      cylindrical: { crs: 'IAU:49900', center: [0, 0], zoom: 2, extent: MARS_EXTENT,       basemapKey: 'cylindrical' },
      north_polar: { crs: 'IAU:49918', center: [0, 0], zoom: 4, extent: MARS_POLAR_EXTENT, basemapKey: 'polar_north', wmsCrs: 'EPSG:32661' },
      south_polar: { crs: 'IAU:49920', center: [0, 0], zoom: 4, extent: MARS_POLAR_EXTENT, basemapKey: 'polar_south', wmsCrs: 'EPSG:32761' },
    },
    moon: {
      cylindrical: { crs: 'IAU:30100', center: [0, 0], zoom: 2, extent: MOON_EXTENT,       basemapKey: 'cylindrical' },
      north_polar: { crs: 'IAU:30118', center: [0, 0], zoom: 4, extent: MOON_POLAR_EXTENT, basemapKey: 'polar_north', wmsCrs: 'EPSG:32661' },
      south_polar: { crs: 'IAU:30120', center: [0, 0], zoom: 4, extent: MOON_POLAR_EXTENT, basemapKey: 'polar_south', wmsCrs: 'EPSG:32761' },
    },
    phobos: {
      cylindrical: { crs: 'IAU:40100', center: [0, 0], zoom: 3, extent: PHOBOS_EXTENT, basemapKey: 'cylindrical' },
    },
  };

  var PROJ_CFG = BODY_PROJ_CFG.mars;

  // -----------------------------------------------------------------------
  // 3. Basemap layer
  // -----------------------------------------------------------------------
  var basemapSource = new ol.source.TileWMS({
    url: 'https://planetarymaps.usgs.gov/cgi-bin/mapserv?map=/maps/mars/mars_simp_cyl.map',
    params: { LAYERS: 'MOLA_color', FORMAT: 'image/jpeg', VERSION: '1.1.1' },
    serverType: 'mapserver',
    projection: 'EPSG:4326',
  });
  var basemapLayer = new ol.layer.Tile({ source: basemapSource });

  function setBasemapSource(url, layerId, wmsCrs) {
    basemapSource = new ol.source.TileWMS({
      url: url,
      params: { LAYERS: layerId, FORMAT: 'image/jpeg', VERSION: '1.1.1' },
      serverType: 'mapserver',
      projection: wmsCrs || 'EPSG:4326',
    });
    basemapLayer.setSource(basemapSource);
  }

  // -----------------------------------------------------------------------
  // 4. Map
  // -----------------------------------------------------------------------
  const map = new ol.Map({
    target: 'porpass-map',
    controls: [],
    layers: [basemapLayer],
    view: new ol.View({
      projection: 'IAU:49900',
      center: [0, 0],
      zoom: 2,
      extent: MARS_EXTENT,
    }),
  });

  // -----------------------------------------------------------------------
  // 5. Mouse position
  // -----------------------------------------------------------------------
  const mousePosEl = document.getElementById('gis-mouse-position');
  map.on('pointermove', function (evt) {
    if (evt.dragging) return;
    var ll  = ol.proj.toLonLat(evt.coordinate, map.getView().getProjection().getCode());
    var lon = ll[0];
    mousePosEl.textContent =
      'Lon: ' + lon.toFixed(2) + '\u00b0  Lat: ' + ll[1].toFixed(2) + '\u00b0';
  });
  map.on('pointerout', function () { mousePosEl.textContent = '\u2014'; });

  // -----------------------------------------------------------------------
  // 6. Basemap switcher
  // -----------------------------------------------------------------------
  var basemapFullCfg = null;

  function repopulateBasemapSelect(layers, activeId) {
    var sel = document.getElementById('basemap-select');
    sel.innerHTML = '';
    layers.forEach(function (layer) {
      var opt = document.createElement('option');
      opt.value = layer.id; opt.textContent = layer.label;
      sel.appendChild(opt);
    });
    sel.value = activeId;
  }

  async function initBasemapSwitcher() {
    var sel = document.getElementById('basemap-select');
    try {
      var resp = await fetch('/gis/api/config/basemaps');
      if (!resp.ok) throw new Error(resp.statusText);
      basemapFullCfg = await resp.json();
      repopulateBasemapSelect(
        basemapFullCfg.mars.cylindrical.layers,
        'MOLA_color'
      );
    } catch (err) {
      console.warn('Could not load basemap config:', err);
      sel.innerHTML = '<option value="MDIM21">MDIM 2.1</option>';
    }
    sel.addEventListener('change', function () {
      basemapSource.updateParams({ LAYERS: sel.value });
    });
  }

  // -----------------------------------------------------------------------
  // 7. Instrument layer state
  // -----------------------------------------------------------------------
  var instruments = {};

  var LAYER_COLORS = {
    sharad:        'rgba(255, 140,   0, 0.65)',
    marsis:        'rgba( 80, 180, 255, 0.65)',
    lrs:           'rgba(120, 220, 120, 0.65)',
    marsis_phobos: 'rgba(255, 100, 180, 0.65)',
  };
  var DEFAULT_COLOR = 'rgba(200, 200, 200, 0.65)';

  function getViewBbox() {
    var ext = map.getView().calculateExtent(map.getSize());
    var crs = map.getView().getProjection().getCode();

    if (currentProj !== 'cylindrical') {
      var lats = [
        [ext[0], ext[1]], [ext[2], ext[1]],
        [ext[2], ext[3]], [ext[0], ext[3]],
      ].map(function (c) { return ol.proj.transform(c, crs, 'EPSG:4326')[1]; });
      var minlat = Math.min.apply(null, lats);
      var maxlat = Math.max.apply(null, lats);
      return currentProj === 'north_polar'
        ? '-180,' + minlat.toFixed(4) + ',180,90'
        : '-180,-90,180,' + maxlat.toFixed(4);
    }

    var sw = ol.proj.transform([ext[0], ext[1]], crs, 'EPSG:4326');
    var ne = ol.proj.transform([ext[2], ext[3]], crs, 'EPSG:4326');
    var minlon = sw[0];
    var maxlon = ne[0];
    if (minlon > maxlon) return null;
    return minlon.toFixed(4) + ',' + sw[1].toFixed(4) + ','
         + maxlon.toFixed(4) + ',' + ne[1].toFixed(4);
  }

  /**
   * Read the user-specified bounding box from the four input fields.
   * Returns a bbox string "minlon,minlat,maxlon,maxlat" if all four
   * fields are filled, or null otherwise.
   */
  function getUserBbox() {
    var minLat = document.getElementById('gis-bbox-min-lat').value.trim();
    var maxLat = document.getElementById('gis-bbox-max-lat').value.trim();
    var minLon = document.getElementById('gis-bbox-min-lon').value.trim();
    var maxLon = document.getElementById('gis-bbox-max-lon').value.trim();
    if (minLat === '' || maxLat === '' || minLon === '' || maxLon === '') return null;
    return minLon + ',' + minLat + ',' + maxLon + ',' + maxLat;
  }

  /** Show or hide the shared bbox section based on whether any instrument is enabled. */
  function updateBboxVisibility() {
    var anyEnabled = Object.keys(instruments).some(function (id) {
      return instruments[id].enabled;
    });
    document.getElementById('gis-bbox-section').style.display = anyEnabled ? 'block' : 'none';
  }

  /** Clear the user bbox input fields. */
  function clearUserBbox() {
    document.getElementById('gis-bbox-min-lat').value = '';
    document.getElementById('gis-bbox-max-lat').value = '';
    document.getElementById('gis-bbox-min-lon').value = '';
    document.getElementById('gis-bbox-max-lon').value = '';
  }

  function buildVectorUrl(instId) {
    var inst  = instruments[instId];
    var parts = [];
    var bbox  = getUserBbox() || getViewBbox();
    if (bbox)                  parts.push('bbox=' + bbox);
    if (inst.activeFilters)    parts.push(inst.activeFilters);
    return '/gis/api/vectors/' + instId + (parts.length ? '?' + parts.join('&') : '');
  }

  async function fetchInstrument(instId) {
    var inst = instruments[instId];
    if (!inst || !inst.enabled) return;
    var url = buildVectorUrl(instId);
    try {
      var resp = await fetch(url);
      if (!resp.ok) throw new Error('HTTP ' + resp.status);
      var geojson  = await resp.json();
      var format   = new ol.format.GeoJSON();
      var features = format.readFeatures(geojson, {
        dataProjection:    'EPSG:4326',
        featureProjection: PROJ_CFG[currentProj].crs,
      });
      inst.source.clear(true);
      inst.source.addFeatures(features);
    } catch (err) {
      console.error(instId + ' fetch failed:', err);
    }
  }

  function enableInstrument(instId) {
    var inst  = instruments[instId];
    var color = LAYER_COLORS[instId] || DEFAULT_COLOR;
    inst.source = new ol.source.Vector();
    inst.layer  = new ol.layer.Vector({
      source: inst.source,
      style: new ol.style.Style({
        stroke: new ol.style.Stroke({ color: color, width: 1.5 }),
      }),
    });
    map.addLayer(inst.layer);
    inst.enabled = true;
    showFilterPanel(instId);
    updateBboxVisibility();
    document.getElementById('inst-f-count-' + instId).textContent =
      'Apply filters to load tracks.';
  }

  function disableInstrument(instId) {
    var inst = instruments[instId];
    inst.enabled = false;
    clearTimeout(inst.timer);
    if (inst.layer) { map.removeLayer(inst.layer); inst.layer = null; inst.source = null; }
    hideFilterPanel(instId);
    updateBboxVisibility();
    closePopup();
  }

  map.on('moveend', function () {
    Object.keys(instruments).forEach(function (instId) {
      var inst = instruments[instId];
      if (!inst.enabled || !inst.initialLoadDone || inst.filterLocked) return;
      clearTimeout(inst.timer);
      inst.timer = setTimeout(function () { fetchInstrument(instId); }, 400);
    });
  });

  // -----------------------------------------------------------------------
  // 8. Projection toggle
  // -----------------------------------------------------------------------
  function switchProjection(key) {
    if (key === currentProj) return;
    currentProj = key;
    var pc = PROJ_CFG[key];

    document.querySelectorAll('.proj-btn').forEach(function (btn) {
      btn.classList.toggle('active', btn.dataset.proj === key);
    });

    map.setView(new ol.View({
      projection: pc.crs,
      center:     pc.center,
      zoom:       pc.zoom,
      extent:     pc.extent,
    }));

    if (basemapFullCfg) {
      var bm      = basemapFullCfg[currentPlanet][pc.basemapKey];
      var firstId = bm.layers[0].id;
      setBasemapSource(bm.url, firstId, pc.wmsCrs);
      repopulateBasemapSelect(bm.layers, firstId);
    }

    closePopup();
    Object.keys(instruments).forEach(function (instId) {
      var inst = instruments[instId];
      if (inst.enabled && inst.initialLoadDone) fetchInstrument(instId);
    });
  }

  document.getElementById('proj-toggle').addEventListener('click', function (e) {
    var btn = e.target.closest('.proj-btn');
    if (btn) switchProjection(btn.dataset.proj);
  });

  // -----------------------------------------------------------------------
  // 9. Planet switcher
  // -----------------------------------------------------------------------
  function switchPlanet(planet) {
    if (planet === currentPlanet) return;
    currentPlanet = planet;

    document.querySelectorAll('.planet-btn').forEach(function (btn) {
      btn.classList.toggle('active', btn.dataset.planet === planet);
    });

    if (planet === 'mars') {
      setPolarWmsCrs(3396190, 3376200, MARS_WMS_POLAR_EXTENT);
    } else if (planet === 'moon') {
      setPolarWmsCrs(1737400, 1737400, MOON_WMS_POLAR_EXTENT);
    }

    document.getElementById('proj-row').style.display = planet === 'phobos' ? 'none' : '';

    PROJ_CFG    = BODY_PROJ_CFG[planet];
    currentProj = 'cylindrical';
    document.querySelectorAll('.proj-btn').forEach(function (btn) {
      btn.classList.toggle('active', btn.dataset.proj === 'cylindrical');
    });

    var pc = PROJ_CFG.cylindrical;
    map.setView(new ol.View({
      projection: pc.crs,
      center:     pc.center,
      zoom:       pc.zoom,
      extent:     pc.extent,
    }));

    if (basemapFullCfg) {
      var bm      = basemapFullCfg[planet].cylindrical;
      var firstId = bm.layers[0].id;
      setBasemapSource(bm.url, firstId);
      repopulateBasemapSelect(bm.layers, firstId);
    }

    closePopup();

    Object.keys(instruments).forEach(function (instId) {
      var inst    = instruments[instId];
      var rowEl   = document.getElementById('inst-row-' + instId);
      var cbEl    = document.getElementById('inst-toggle-' + instId);
      var matches = inst.config.body === planet;

      if (rowEl) rowEl.style.display = matches ? '' : 'none';

      if (inst.enabled) {
        cbEl.checked = false;
        disableInstrument(instId);
      }
    });
  }

  document.getElementById('planet-toggle').addEventListener('click', function (e) {
    var btn = e.target.closest('.planet-btn');
    if (btn) switchPlanet(btn.dataset.planet);
  });

  // -----------------------------------------------------------------------
  // 10. Feature click popup
  // -----------------------------------------------------------------------
  var popupEl = document.getElementById('gis-popup');
  var popupOverlay = new ol.Overlay({
    element:     popupEl,
    positioning: 'bottom-left',
    stopEvent:   true,
    offset:      [8, -8],
  });
  map.addOverlay(popupOverlay);

  function closePopup() {
    popupOverlay.setPosition(undefined);
    popupEl.style.display = 'none';
  }

  document.getElementById('gis-popup-closer').addEventListener('click', closePopup);

  map.on('singleclick', function (evt) {
    var feature  = null;
    var instId   = null;

    Object.keys(instruments).forEach(function (id) {
      var inst = instruments[id];
      if (!inst.enabled || !inst.layer || feature) return;
      map.forEachFeatureAtPixel(evt.pixel, function (f, layer) {
        if (layer === inst.layer) { feature = f; instId = id; return true; }
      }, { hitTolerance: 6 });
    });

    if (!feature || !instId) { closePopup(); return; }

    var inst       = instruments[instId];
    var popupFields = inst.config.popup_fields || [];
    var props      = feature.getProperties();

    var rows = popupFields.map(function (pf) {
      var val = props[pf.field];
      if (val === null || val === undefined || val === '') return '';
      var display;
      if (pf.type === 'url') {
        display = '<a href="' + val + '" target="_blank" rel="noopener">View \u2192</a>';
      } else {
        display = String(val);
      }
      return '<tr><td>' + pf.label + '</td><td>' + display + '</td></tr>';
    }).join('');

    document.getElementById('gis-popup-title').textContent = inst.config.label + ' Track';

    var html = '<table class="popup-table">' + rows + '</table>';

    // Append browse product thumbnail if available
    var browseUrl = props.browse_url;
    if (browseUrl) {
      html += '<div class="gis-popup-browse">'
        + '<a href="' + browseUrl + '" target="_blank" rel="noopener">'
        + '<img src="' + browseUrl + '" alt="Browse product">'
        + '</a>'
        + '<span class="browse-label">Browse Product</span>'
        + '</div>';
    }

    // "Add to processing queue" button — only when the feature carries an
    // observation_id (all instruments include it in select_columns today, but
    // guard so the popup still renders if a future config forgets to).
    if (props.observation_id) {
      html += '<div class="gis-popup-actions" style="margin-top: 0.75rem; text-align: right;">'
        + '<button type="button" class="pp-btn pp-btn-sm pp-btn-primary" '
        + 'onclick="addObsToQueue(' + parseInt(props.observation_id, 10) + ', this)">'
        + 'Add to processing queue</button></div>';
    }

    document.getElementById('gis-popup-content').innerHTML = html;

    popupEl.style.display = '';
    popupOverlay.setPosition(evt.coordinate);
  });

  // ── Processing queue: single-observation add from the popup ───────────────
  //
  // The observations page has a batch flow; the map has one selected feature
  // at a time, so this adds a single observation_id and morphs the button in
  // place to signal success without a page reload.
  window.addObsToQueue = function (observationId, btn) {
    if (!observationId) return;
    if (btn) {
      btn.disabled    = true;
      btn.dataset.orig = btn.textContent;
      btn.textContent  = 'Adding…';
    }
    fetch('/api/processing_queue.php', {
      method:  'POST',
      headers: {'Content-Type': 'application/json'},
      body:    JSON.stringify({action: 'add', observation_ids: [observationId]}),
    })
      .then(function (r) { return r.json().then(function (j) { return {r: r, j: j}; }); })
      .then(function (x) {
        if (!btn) return;
        if (!x.r.ok || !x.j.ok) {
          btn.disabled    = false;
          btn.textContent = 'Add failed — retry';
          console.error('Add-to-queue error:', x.j.error || x.r.statusText);
          return;
        }
        btn.classList.remove('pp-btn-primary');
        btn.classList.add('pp-btn-success');
        btn.textContent = x.j.added > 0 ? '✓ Added to queue' : '✓ Already in queue';
        var badge = document.getElementById('nav-queue-badge');
        if (badge) {
          badge.textContent   = x.j.queue_count;
          badge.style.display = x.j.queue_count > 0 ? '' : 'none';
        }
      })
      .catch(function (e) {
        if (btn) {
          btn.disabled    = false;
          btn.textContent = 'Add failed — retry';
        }
        console.error('Add-to-queue network error:', e);
      });
  };

  // -----------------------------------------------------------------------
  // 11. Filter panel
  // -----------------------------------------------------------------------
  function showFilterPanel(instId) {
    var panelEl = document.getElementById('inst-filters-' + instId);
    if (!panelEl) return;
    panelEl.style.display = '';
    if (instruments[instId].filtersBuilt) return;
    buildFilterPanel(instId);
  }

  function hideFilterPanel(instId) {
    var panelEl = document.getElementById('inst-filters-' + instId);
    if (panelEl) panelEl.style.display = 'none';
  }

  function buildFilterPanel(instId) {
    var inst    = instruments[instId];
    var defs    = (inst.config.filters || []).filter(function (f) { return f.type !== 'bbox'; });
    inst.filterDefs    = defs;
    inst.filtersBuilt  = true;
    inst.dropdownLoads = [];  // promises for async (data-driven) dropdown options

    var container  = document.getElementById('inst-f-rows-' + instId);
    var hasRequired = defs.some(function (f) { return f.required; });

    // If any filter is required, create a hidden wrapper for the optional ones
    var optionalWrap;
    if (hasRequired) {
      optionalWrap = document.createElement('div');
      optionalWrap.id = 'inst-f-optional-' + instId;
      optionalWrap.style.display = 'none';
      container.appendChild(optionalWrap);
    }

    defs.forEach(function (f) {
      var row = document.createElement('div');
      row.className = 'filter-row';
      row.id = 'f-row-' + instId + '-' + f.id;

      if (f.type === 'range') {
        if (f.ui === 'max_only') {
          row.innerHTML =
            '<span class="f-label">' + f.label + ' \u2264</span>'
            + '<input type="number" id="f-' + instId + '-' + f.id + '"'
            + '  min="' + f.min + '" max="' + f.max + '"'
            + '  placeholder="' + f.max + '"'
            + '  class="f-input-full">';
        } else {
          row.innerHTML =
            '<span class="f-label">' + f.label + '</span>'
            + '<div class="f-range">'
            + '  <input type="number" id="f-' + instId + '-' + f.id + '-min"'
            + '    min="' + f.min + '" max="' + f.max + '"'
            + '    placeholder="' + f.min + '">'
            + '  <span class="f-dash">\u2013</span>'
            + '  <input type="number" id="f-' + instId + '-' + f.id + '-max"'
            + '    min="' + f.min + '" max="' + f.max + '"'
            + '    placeholder="' + f.max + '">'
            + '</div>';
        }
      } else if (f.type === 'dropdown') {
        var emptyLabel = f.required ? '\u2014 Select \u2014' : 'All';
        row.innerHTML =
          '<span class="f-label">' + f.label + '</span>'
          + '<select id="f-' + instId + '-' + f.id + '" style="flex:1">'
          + '<option value="">' + emptyLabel + '</option>'
          + '</select>';
      }

      // Required filters go in the main container before the optional wrapper;
      // optional ones go inside the wrapper
      if (f.required || !hasRequired) {
        if (optionalWrap) {
          container.insertBefore(row, optionalWrap);
        } else {
          container.appendChild(row);
        }
      } else {
        optionalWrap.appendChild(row);
      }

      if (f.type === 'dropdown') {
        if (f.options && f.options.length > 0) {
          var sel = document.getElementById('f-' + instId + '-' + f.id);
          f.options.forEach(function (v) {
            var opt = document.createElement('option');
            opt.value = v; opt.textContent = v;
            sel.appendChild(opt);
          });
          // Options exist synchronously, so the default can be set now.
          if (f.default !== undefined) sel.value = f.default;
        } else {
          // Options arrive asynchronously; populateFilterDropdown applies
          // the default itself once the options have been appended. Track the
          // promise so URL-param restore can wait for the options to exist.
          inst.dropdownLoads.push(populateFilterDropdown(instId, f));
        }

        // Hide the row for defaulted filters. The value is set above for
        // hardcoded options, or inside populateFilterDropdown for async ones.
        if (f.default !== undefined) {
          row.style.display = 'none';
          row.dataset.hasDefault = 'true';
        }

        // When a required dropdown changes, show/hide optional filters + Apply,
        // and conditionally hide filters that declare hidden_when
        if (f.required) {
          (function (filterId, iid, allDefs) {
            var sel = document.getElementById('f-' + iid + '-' + filterId);
            sel.addEventListener('change', function () {
              var val  = sel.value;
              var show = val !== '';
              document.getElementById('inst-f-optional-' + iid).style.display = show ? '' : 'none';
              document.getElementById('inst-f-apply-' + iid).disabled = !show;

              // Apply hidden_when rules to sibling filters
              allDefs.forEach(function (d) {
                if (!d.hidden_when) return;
                var rowEl = document.getElementById('f-row-' + iid + '-' + d.id);
                if (!rowEl) return;
                var hideVals = d.hidden_when[filterId] || [];
                rowEl.style.display = (show && hideVals.indexOf(val) !== -1) ? 'none' : '';
              });
            });
          })(f.id, instId, defs);
        }
      }
    });

    if (hasRequired) {
      // Start with Apply disabled until required filter is set
      document.getElementById('inst-f-apply-' + instId).disabled = true;
    }
  }

  async function populateFilterDropdown(instId, filterDef) {
    try {
      var resp = await fetch('/gis/api/vectors/' + instId + '/field-values/' + filterDef.field);
      if (!resp.ok) throw new Error(resp.statusText);
      var data = await resp.json();
      var sel  = document.getElementById('f-' + instId + '-' + filterDef.id);
      data.values.forEach(function (v) {
        var opt = document.createElement('option');
        opt.value = v; opt.textContent = v;
        sel.appendChild(opt);
      });
      // Apply the configured default now that the options exist. Setting it
      // synchronously in the caller would race this fetch and reset to "".
      if (filterDef.default !== undefined) sel.value = filterDef.default;
    } catch (err) {
      console.warn('Could not load field values for ' + filterDef.field, err);
    }
  }

  function buildActiveFilters(instId) {
    var inst = instruments[instId];
    if (!inst.filterDefs) return '';
    var parts = [];

    inst.filterDefs.forEach(function (f) {
      // Skip filters whose row is hidden (e.g. via hidden_when rules),
      // but allow filters hidden because they have a default value
      var rowEl = document.getElementById('f-row-' + instId + '-' + f.id);
      if (rowEl && rowEl.style.display === 'none' && !rowEl.dataset.hasDefault) return;

      if (f.type === 'range') {
        if (f.ui === 'max_only') {
          var el = document.getElementById('f-' + instId + '-' + f.id);
          if (el && el.value !== '') parts.push(f.field + '_max=' + encodeURIComponent(el.value));
        } else {
          var minEl = document.getElementById('f-' + instId + '-' + f.id + '-min');
          var maxEl = document.getElementById('f-' + instId + '-' + f.id + '-max');
          if (minEl && minEl.value !== '') parts.push(f.field + '_min=' + encodeURIComponent(minEl.value));
          if (maxEl && maxEl.value !== '') parts.push(f.field + '_max=' + encodeURIComponent(maxEl.value));
        }
      } else if (f.type === 'dropdown') {
        var sel = document.getElementById('f-' + instId + '-' + f.id);
        if (sel && sel.value !== '') parts.push(f.field + '=' + encodeURIComponent(sel.value));
      }
    });

    return parts.join('&');
  }

  async function applyFilters(instId) {
    var inst = instruments[instId];
    inst.activeFilters   = buildActiveFilters(instId);
    inst.initialLoadDone = true;
    inst.filterLocked    = true;
    updateBrowseLink();

    // If the user specified a manual bbox, fit the map view to it
    var userBbox = getUserBbox();
    if (userBbox) {
      var parts = userBbox.split(',').map(Number);  // minlon, minlat, maxlon, maxlat
      var crs   = map.getView().getProjection().getCode();
      var sw    = ol.proj.fromLonLat([parts[0], parts[1]], crs);
      var ne    = ol.proj.fromLonLat([parts[2], parts[3]], crs);
      map.getView().fit([sw[0], sw[1], ne[0], ne[1]], {
        padding: [20, 20, 20, 20],
        duration: 300,
      });
    }

    fetchInstrument(instId);

    var bbox = userBbox || getViewBbox();
    var countParts = [];
    if (bbox) countParts.push('bbox=' + bbox);
    if (inst.activeFilters) countParts.push(inst.activeFilters);
    var countUrl = '/gis/api/vectors/' + instId + '/count'
                 + (countParts.length ? '?' + countParts.join('&') : '');
    try {
      var resp = await fetch(countUrl);
      if (!resp.ok) throw new Error(resp.statusText);
      var data = await resp.json();
      document.getElementById('inst-f-count-' + instId).textContent =
        'Showing ' + data.filtered.toLocaleString()
        + ' of ' + data.total.toLocaleString() + ' features';
    } catch (err) {
      console.warn('Count fetch failed:', err);
    }
    updateBrowseLink();
  }

  function resetFilters(instId) {
    var inst = instruments[instId];
    if (!inst.filterDefs) return;
    inst.filterDefs.forEach(function (f) {
      if (f.type === 'range') {
        if (f.ui === 'max_only') {
          var el = document.getElementById('f-' + instId + '-' + f.id);
          if (el) el.value = '';
        } else {
          var minEl = document.getElementById('f-' + instId + '-' + f.id + '-min');
          var maxEl = document.getElementById('f-' + instId + '-' + f.id + '-max');
          if (minEl) minEl.value = '';
          if (maxEl) maxEl.value = '';
        }
      } else if (f.type === 'dropdown') {
        var sel = document.getElementById('f-' + instId + '-' + f.id);
        if (sel) sel.value = (f.default !== undefined) ? f.default : '';
      }
    });
    inst.activeFilters   = '';
    inst.initialLoadDone = false;
    inst.filterLocked    = false;
    clearUserBbox();
    if (inst.source) inst.source.clear(true);
    // Re-hide optional filters and disable Apply if a required filter exists
    var optWrap = document.getElementById('inst-f-optional-' + instId);
    if (optWrap) {
      optWrap.style.display = 'none';
      document.getElementById('inst-f-apply-' + instId).disabled = true;
    }
    document.getElementById('inst-f-count-' + instId).textContent =
      'Apply filters to load tracks.';
    updateBrowseLink();
  }

  // -----------------------------------------------------------------------
  // 12. Instrument panel
  // -----------------------------------------------------------------------
  async function initInstrumentPanel() {
    var container = document.getElementById('instrument-layers');
    var cfg;
    try {
      var resp = await fetch('/gis/api/config/instruments');
      if (!resp.ok) throw new Error(resp.statusText);
      cfg = await resp.json();
    } catch (err) {
      console.error('Could not load instrument config:', err);
      container.innerHTML = '<div style="color:#555;font-size:11px">Could not load instruments</div>';
      return;
    }

    cfg.instruments.forEach(function (instCfg) {
      var id      = instCfg.id;
      var enabled = instCfg.enabled;

      instruments[id] = {
        config:       instCfg,
        layer:        null,
        source:       null,
        enabled:      false,
        timer:        null,
        activeFilters: '',
        filterDefs:    null,
        filtersBuilt:  false,
        initialLoadDone: false,
        filterLocked:    false,
      };

      var row = document.createElement('div');
      row.className   = 'ctrl-row';
      row.id          = 'inst-row-' + id;
      row.style.display = instCfg.body === currentPlanet ? '' : 'none';

      if (enabled) {
        row.innerHTML =
          '<label class="layer-toggle">'
          + '<input type="checkbox" id="inst-toggle-' + id + '">'
          + instCfg.label
          + '</label>';
      } else {
        row.innerHTML =
          '<label class="layer-toggle pending">'
          + '<input type="checkbox" id="inst-toggle-' + id + '" disabled>'
          + instCfg.label + ' \u2014 data pending'
          + '</label>';
      }
      container.appendChild(row);

      if (enabled) {
        document.getElementById('inst-toggle-' + id).addEventListener('change', function (e) {
          if (e.target.checked) { enableInstrument(id); } else { disableInstrument(id); }
        });
      }

      if (!enabled || !instCfg.filters || instCfg.filters.length === 0) return;

      var panel = document.createElement('div');
      panel.id            = 'inst-filters-' + id;
      panel.style.display = 'none';
      panel.innerHTML =
        '<hr class="ctrl-sep">'
        + '<div id="inst-f-rows-' + id + '"></div>'
        + '<div class="filter-buttons">'
        + '  <button class="f-btn f-btn-apply" id="inst-f-apply-' + id + '">Apply Filters</button>'
        + '  <button class="f-btn f-btn-reset" id="inst-f-reset-' + id + '">Reset</button>'
        + '</div>'
        + '<div class="f-count" id="inst-f-count-' + id + '"></div>';
      container.appendChild(panel);

      (function (instId) {
        document.getElementById('inst-f-apply-' + instId).addEventListener('click', function () {
          applyFilters(instId);
        });
        document.getElementById('inst-f-reset-' + instId).addEventListener('click', function () {
          resetFilters(instId);
        });
      })(id);
    });
  }

  // -----------------------------------------------------------------------
  // 13. Browse Details link (GIS → observations.php)
  // -----------------------------------------------------------------------

  /** GIS instrument ID → PHP instrument_id and body_id */
  var GIS_TO_PHP = {
    sharad:        { instrument_id: 2, body_id: 1 },
    marsis:        { instrument_id: 3, body_id: 1 },
    marsis_phobos: { instrument_id: 3, body_id: 4 },
    lrs:           { instrument_id: 1, body_id: 3 },
  };

  /** PHP instrument_id + body_id → GIS instrument ID */
  var PHP_TO_GIS = {
    '2_1': 'sharad',
    '3_1': 'marsis',
    '3_4': 'marsis_phobos',
    '1_3': 'lrs',
  };

  /**
   * Build a URL to observations.php that reproduces the current GIS filters.
   * observations.php uses GET params (same names as its POST params).
   */
  function buildBrowseUrl() {
    var enabledInsts = Object.keys(instruments).filter(function (id) {
      return instruments[id].enabled && instruments[id].initialLoadDone;
    });
    if (enabledInsts.length === 0) return null;

    var params = new URLSearchParams();

    // Use the first enabled instrument for body/instrument selection
    // (observations.php can only filter one instrument at a time)
    var firstInst = enabledInsts[0];
    var mapping   = GIS_TO_PHP[firstInst];
    if (mapping) {
      params.set('body_id',       mapping.body_id);
      params.set('instrument_id', mapping.instrument_id);
    }

    // Bounding box — prefer user-specified, fall back to viewport
    var bboxStr = getUserBbox() || getViewBbox();
    if (bboxStr) {
      var bp = bboxStr.split(',');
      params.set('bbox_min_lon', bp[0]);
      params.set('bbox_min_lat', bp[1]);
      params.set('bbox_max_lon', bp[2]);
      params.set('bbox_max_lat', bp[3]);
    }

    // Translate GIS filter params to observations.php param names
    var inst = instruments[firstInst];
    if (inst.activeFilters) {
      var gisParams = new URLSearchParams(inst.activeFilters);

      // Field name mapping: GIS API → observations.php POST
      var fieldMap = {
        'observation_type': 'product_type',
        'mean_sza_min': 'sza_min',
        'mean_sza_max': 'sza_max',
        'l_s_min':      'ls_min',
        'l_s_max':      'ls_max',
        'max_roll_max': 'max_roll',
        'orbit_number_min': 'orbit_min',
        'orbit_number_max': 'orbit_max',
      };

      gisParams.forEach(function (val, key) {
        var phpKey = fieldMap[key] || key;
        if (key === 'presum') {
          params.append('presums[]', val);
        } else if (key === 'mode_name') {
          // Mode is a checkbox group in observations.php, keyed per
          // instrument, so it must be sent as an array element
          // (lrs_modes[] / marsis_modes[]) rather than a scalar.
          if (firstInst === 'lrs') {
            params.append('lrs_modes[]', val);
          } else if (firstInst === 'marsis' || firstInst === 'marsis_phobos') {
            params.append('marsis_modes[]', val);
          }
        } else {
          params.set(phpKey, val);
        }
      });
    }

    return '/observations.php?' + params.toString();
  }

  /** Update the Browse Details link state — active or greyed out. */
  function updateBrowseLink() {
    var link = document.getElementById('gis-browse-link');
    var url  = buildBrowseUrl();
    if (url) {
      link.href = url;
      link.classList.add('active');
    } else {
      link.href = '#';
      link.classList.remove('active');
    }
  }

  // -----------------------------------------------------------------------
  // 14. URL parameter handling (observations.php → GIS)
  // -----------------------------------------------------------------------

  /**
   * Read URL query parameters and auto-configure the map.
   * Expected params: planet, instruments (comma-separated GIS IDs),
   * bbox (minlon,minlat,maxlon,maxlat), plus any filter params.
   */
  async function applyUrlParams() {
    var params = new URLSearchParams(window.location.search);
    if (!params.has('instruments')) return;

    // Switch planet if needed
    var planet = params.get('planet');
    if (planet && planet !== currentPlanet) {
      switchPlanet(planet);
    }

    // Wait for instrument panel to initialise
    await new Promise(function (resolve) { setTimeout(resolve, 300); });

    // Populate bounding box if provided
    var bbox = params.get('bbox');
    if (bbox) {
      var bp = bbox.split(',');
      if (bp.length === 4) {
        document.getElementById('gis-bbox-min-lon').value = bp[0];
        document.getElementById('gis-bbox-min-lat').value = bp[1];
        document.getElementById('gis-bbox-max-lon').value = bp[2];
        document.getElementById('gis-bbox-max-lat').value = bp[3];
      }
    }

    // Enable each requested instrument and populate its filters
    var instIds = params.get('instruments').split(',');
    instIds.forEach(function (instId) {
      var inst = instruments[instId];
      if (!inst || !inst.config.enabled) return;

      // Toggle the checkbox on
      var cb = document.getElementById('inst-toggle-' + instId);
      if (cb && !cb.checked) {
        cb.checked = true;
        enableInstrument(instId);
      }

      // Wait for filter panel to build, then populate fields
      setTimeout(async function () {
        if (!inst.filterDefs) return;

        // Wait for any data-driven dropdowns to finish loading their options,
        // otherwise setting el.value below would race the fetch and reset to "".
        await Promise.all(inst.dropdownLoads || []);

        inst.filterDefs.forEach(function (f) {
          if (f.type === 'range') {
            if (f.ui === 'max_only') {
              var maxVal = params.get(f.field + '_max');
              if (maxVal) {
                var el = document.getElementById('f-' + instId + '-' + f.id);
                if (el) el.value = maxVal;
              }
            } else {
              var minVal = params.get(f.field + '_min');
              var maxVal = params.get(f.field + '_max');
              if (minVal) {
                var el = document.getElementById('f-' + instId + '-' + f.id + '-min');
                if (el) el.value = minVal;
              }
              if (maxVal) {
                var el = document.getElementById('f-' + instId + '-' + f.id + '-max');
                if (el) el.value = maxVal;
              }
            }
          } else if (f.type === 'dropdown') {
            var val = params.get(f.field);
            if (val) {
              var el = document.getElementById('f-' + instId + '-' + f.id);
              if (el) el.value = val;
              // If this is a required filter, show the optional filters
              // and apply hidden_when rules
              if (f.required) {
                var optWrap = document.getElementById('inst-f-optional-' + instId);
                if (optWrap) optWrap.style.display = '';
                document.getElementById('inst-f-apply-' + instId).disabled = false;
                inst.filterDefs.forEach(function (d) {
                  if (!d.hidden_when) return;
                  var rowEl = document.getElementById('f-row-' + instId + '-' + d.id);
                  if (!rowEl) return;
                  var hideVals = d.hidden_when[f.id] || [];
                  rowEl.style.display = hideVals.indexOf(val) !== -1 ? 'none' : '';
                });
              }
            }
          }
        });

        // Auto-apply filters
        applyFilters(instId);
      }, 400);
    });
  }

  // -----------------------------------------------------------------------
  // 15. Init
  // -----------------------------------------------------------------------
  initBasemapSwitcher();
  initInstrumentPanel().then(function () {
    applyUrlParams();
  });

  // -----------------------------------------------------------------------
  // 16. Info modal
  // -----------------------------------------------------------------------
  document.getElementById('gis-info-btn').addEventListener('click', function () {
    document.getElementById('gis-info-overlay').style.display = 'flex';
  });
  document.getElementById('gis-info-close').addEventListener('click', function () {
    document.getElementById('gis-info-overlay').style.display = 'none';
  });
  document.getElementById('gis-info-overlay').addEventListener('click', function (e) {
    if (e.target === this) this.style.display = 'none';
  });

  document.getElementById('gis-zoom-in').onclick = function () {
    var view = map.getView();
    view.setZoom(view.getZoom() + 1);
  };
  document.getElementById('gis-zoom-out').onclick = function () {
    var view = map.getView();
    view.setZoom(view.getZoom() - 1);
  };
</script>

<?php close_layout(); ?>