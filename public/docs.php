<?php
/**
 * docs.php — PORPASS documentation landing page.
 *
 * Displays a cards grid linking to each documentation section:
 * instrument pages, GRaSP, OaRS, and FAQ. Content for each section
 * lives in its own file under public/docs/.
 */

require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/layout.php';

session_start_secure();
require_login();

open_layout('Documentation');
?>

<div class="pp-container-medium" style="max-width: 1100px;">

    <p class="pp-section-label" style="margin-bottom: 0.25rem;">Documentation</p>
    <h1 class="pp-page-title-large">Reference and user guides</h1>
    <p class="pp-lead" style="margin-top: 0.5rem; margin-bottom: 2.5rem;">
        Instrument overviews, software documentation, and reference materials for PORPASS.
    </p>

    <!-- ── Instruments ────────────────────────────────────────────────────── -->
    <h2 class="pp-docs-section-heading">Instruments</h2>
    <div class="pp-card-grid" style="margin-top: 0; margin-bottom: 3rem;">

        <a href="/docs/sharad.php" style="text-decoration: none; color: inherit;">
            <div class="pp-card" style="height: 100%; display: flex; flex-direction: column;">
                <h3 class="pp-card-title">MRO SHARAD</h3>
                <p class="pp-card-body" style="flex: 1;">
                    Shallow Radar onboard NASA's Mars Reconnaissance Orbiter.
                    Operating since 2006 at 15–25 MHz with 15 m free-space
                    range resolution.
                </p>
                <div class="pp-card-footer">View documentation →</div>
            </div>
        </a>

        <a href="/docs/marsis.php" style="text-decoration: none; color: inherit;">
            <div class="pp-card" style="height: 100%; display: flex; flex-direction: column;">
                <h3 class="pp-card-title">MEx MARSIS</h3>
                <p class="pp-card-body" style="flex: 1;">
                    Mars Advanced Radar for Subsurface and Ionosphere Sounding
                    onboard ESA's Mars Express. Operating since 2005 at
                    1.3–5.5 MHz with 150 m free-space range resolution.
                </p>
                <div class="pp-card-footer">View documentation →</div>
            </div>
        </a>

        <a href="/docs/lrs.php" style="text-decoration: none; color: inherit;">
            <div class="pp-card" style="height: 100%; display: flex; flex-direction: column;">
                <h3 class="pp-card-title">SELENE LRS</h3>
                <p class="pp-card-body" style="flex: 1;">
                    Lunar Radar Sounder onboard JAXA's SELENE/Kaguya spacecraft.
                    Operated 2007–2009 at 4–6 MHz with 75 m free-space
                    range resolution.
                </p>
                <div class="pp-card-footer">View documentation →</div>
            </div>
        </a>

    </div>

    <!-- ── Software ───────────────────────────────────────────────────────── -->
    <h2 class="pp-docs-section-heading">Software</h2>
    <div class="pp-card-grid" style="margin-top: 0; margin-bottom: 3rem;">

        <div class="pp-card pp-card--placeholder" style="height: 100%; display: flex; flex-direction: column;">
            <h3 class="pp-card-title">GRaSP</h3>
            <p class="pp-card-body" style="flex: 1;">
                Generalized Radar Sounder Processor — the core processing
                engine behind PORPASS. API reference documentation
                auto-generated from the GRaSP Python library.
            </p>
            <div class="pp-card-footer pp-card-footer--muted">Coming soon</div>
        </div>

        <div class="pp-card pp-card--placeholder" style="height: 100%; display: flex; flex-direction: column;">
            <h3 class="pp-card-title">OaRS</h3>
            <p class="pp-card-body" style="flex: 1;">
                Orbital Radar Simulator — user guide for simulating radar
                sounder observations through free-form subsurface environments.
            </p>
            <div class="pp-card-footer pp-card-footer--muted">Coming soon</div>
        </div>

    </div>

    <!-- ── Other ──────────────────────────────────────────────────────────── -->
    <h2 class="pp-docs-section-heading">Other</h2>
    <div class="pp-card-grid" style="margin-top: 0;">

        <div class="pp-card pp-card--placeholder" style="height: 100%; display: flex; flex-direction: column;">
            <h3 class="pp-card-title">FAQ</h3>
            <p class="pp-card-body" style="flex: 1;">
                Frequently asked questions about PORPASS, data access,
                processing jobs, and account management.
            </p>
            <div class="pp-card-footer pp-card-footer--muted">Coming soon</div>
        </div>

    </div>

</div>

<?php close_layout(); ?>