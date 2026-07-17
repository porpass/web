<?php
/**
 * sharad.php — MRO SHARAD instrument documentation.
 *
 * Covers overview, technical specifications, instrument design,
 * selected science highlights, and references for the Shallow Radar
 * (SHARAD) onboard NASA's Mars Reconnaissance Orbiter.
 */

require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/layout.php';

session_start_secure();
require_login();

open_layout('SHARAD — Documentation');
?>

<div class="pp-docs-layout">

    <!-- ── Sidebar ───────────────────────────────────────────────────────── -->
    <aside class="pp-docs-sidebar">
        <nav id="docs-nav" class="pp-docs-nav">
            <a class="pp-docs-nav-back" href="/docs.php">← All documentation</a>
            <hr class="pp-docs-nav-divider">
            <p class="pp-docs-nav-label">MRO SHARAD</p>
            <a class="pp-docs-nav-link" href="#overview">Overview</a>
            <a class="pp-docs-nav-link" href="#specs">Technical specs</a>
            <a class="pp-docs-nav-link" href="#design">Instrument design</a>
            <a class="pp-docs-nav-link" href="#science">Science highlights</a>
            <a class="pp-docs-nav-link" href="#references">References</a>
        </nav>
    </aside>

    <!-- ── Main content ──────────────────────────────────────────────────── -->
    <div class="pp-docs-content">

        <p class="pp-section-label" style="margin-bottom: 0.25rem;">Documentation</p>
        <h1 class="pp-page-title-large">MRO SHARAD</h1>
        <p class="pp-lead" style="margin-top: 0.5rem; margin-bottom: 1.5rem;">
            Shallow Radar &mdash; Mars Reconnaissance Orbiter (NASA)
        </p>

        <div class="pp-link-row">
            <a href="https://sharad.psi.edu" target="_blank" rel="noopener noreferrer"
               class="pp-link-btn">SHARAD at PSI ↗</a>
            <a href="https://pds-geosciences.wustl.edu/missions/mro/sharad.htm"
               target="_blank" rel="noopener noreferrer"
               class="pp-link-btn">PDS archive ↗</a>
            <a href="https://mropd.psi.edu/browse.php?inst=SHARAD"
               target="_blank" rel="noopener noreferrer"
               class="pp-link-btn">Publication database ↗</a>
        </div>

        <!-- Overview -->
        <section id="overview" class="pp-docs-section">
            <h2>Overview</h2>
            <p>
                The Shallow Radar (SHARAD) is a subsurface sounding radar onboard
                NASA's Mars Reconnaissance Orbiter (MRO). SHARAD was provided to MRO
                by the <a href="https://www.asi.it/en/" target="_blank" rel="noopener noreferrer">Agenzia
                Spaziale Italiana (ASI)</a> and is operated under contract to SHARAD Team
                Leader Pierfrancesco Lombardo at the Sapienza - Università di Roma.
            </p>
            <p>
                SHARAD began its primary science phase in October 2006 and has been in
                continuous operation for nearly 20 years, acquiring data along more than
                38,000 discrete orbit segments (as of April 4, 2026) covering approximately
                62% of the Martian surface at its nominal 3-km cross-track resolution, an
                increase from the ~57% reported by Putzig et al. (2024). Coverage
                is greater than 90% poleward of approximately 75–80° latitude in each hemisphere.
            </p>
            <p>
                SHARAD's primary objective is to map dielectric interfaces in the
                Martian subsurface to depths of up to a few kilometers and to interpret
                these results in terms of the distribution of rock, sediment, regolith,
                water, and ice. The instrument has provided transformative insights into
                Martian polar stratigraphy, mid-latitude glacial deposits, volcanic
                structure, and the behavior of the Martian ionosphere.
            </p>
            <p>
                SHARAD provides complementary data to MARSIS onboard Mars Express.
                Operating at higher frequencies and wider bandwidth than MARSIS,
                SHARAD achieves finer vertical resolution at the cost of shallower
                penetration depth.
            </p>
            <p>
                For a comprehensive list of SHARAD-related publications, visit the
                <a href="https://mropd.psi.edu/browse.php?inst=SHARAD" target="_blank"
                   rel="noopener noreferrer">MRO Publication Database</a>.
            </p>
        </section>

        <!-- Technical specs -->
        <section id="specs" class="pp-docs-section">
            <h2>Technical specifications</h2>
            <table class="pp-spec-table">
                <tbody>
                    <tr><th>Platform</th><td>MRO (NASA)</td></tr>
                    <tr><th>Target body</th><td>Mars</td></tr>
                    <tr><th>Frequency range</th><td>15–25 MHz</td></tr>
                    <tr><th>Bandwidth</th><td>10 MHz</td></tr>
                    <tr><th>Pulse width</th><td>85 µs</td></tr>
                    <tr><th>PRF</th><td>700.28 Hz</td></tr>
                    <tr><th>Peak TX power</th><td>10 W</td></tr>
                    <tr><th>Range resolution</th><td>15 m (free-space)</td></tr>
                    <tr><th>Along-track res.</th><td>300–450 m</td></tr>
                    <tr><th>Cross-track res.</th><td>~3 km (Fresnel zone)</td></tr>
                    <tr><th>Orbital altitude</th><td>~300 km</td></tr>
                    <tr><th>Operations</th><td>Oct 2006 – present</td></tr>
                </tbody>
            </table>
        </section>

        <!-- Instrument design -->
        <section id="design" class="pp-docs-section">
            <h2>Instrument design</h2>
            <p>
                SHARAD transmits chirped pulses downswept from 25 to 15 MHz over an
                85-µs pulse width, with a pulse repetition frequency of 700.28 Hz. The
                10-MHz bandwidth yields a nominal 15-m free-space range resolution.
                The instrument uses a 10-m dipole antenna for both transmitting and
                receiving.
            </p>
            <p>
                Data are processed into two-dimensional radargrams — profile images
                showing returned radar power with delay time on the vertical axis and
                along-track distance on the horizontal axis - using synthetic aperture
                techniques. Advanced techniques including subband processing, incoherent summing,
                superresolution, and full three-dimensional radar imaging have been developed
                to extend data utility.
            </p>
            <p>
                Because SHARAD's antenna was mounted on the zenith deck, the MRO Project
                executes roll maneuvers during observations to reduce spacecraft body
                interference with the radar signal. Moderate rolls of up to 28° provide an
                average 6-dB signal-to-noise improvement.
            </p>
        </section>

        <!-- Science highlights -->
        <section id="science" class="pp-docs-section">
            <h2>Selected science highlights</h2>
            <ul class="pp-highlight-list">
                <li class="pp-highlight-item">
                    <p><span class="pp-highlight-item-title">Polar stratigraphy</span> — SHARAD has detected up to 48
                    reflecting interfaces within the Martian north polar layered deposits,
                    penetrating to depths of 1-2 km and revealing complex climate records.
                    Three-dimensional radar imaging of both polar regions has clarified
                    internal stratigraphy and resolved off-nadir surface clutter.</p>
                    <p class="pp-reference">
                        Putzig, N. E. et al. (2009).
                        <strong>Subsurface structure of Planum Boreum from Mars Reconnaissance Orbiter shallow radar soundings.</strong>
                        <em>Icarus</em>, 204(2), 443-457.
                        <a href="https://doi.org/10.1016/j.icarus.2009.07.034" target="_blank" rel="noopener noreferrer">https://doi.org/10.1016/j.icarus.2009.07.034</a>
                    </p>
                    <p class="pp-reference">
                        Putzig, N. E. et al. (2018).
                        <strong>Three-dimensional radar imaging of structures and craters in the Martian polar caps.</strong>
                        <em>Icarus</em>, 308, 138-147.
                        <a href="https://doi.org/10.1016/j.icarus.2017.09.023" target="_blank" rel="noopener noreferrer">https://doi.org/10.1016/j.icarus.2017.09.023</a>
                    </p>
                </li>
                <li class="pp-highlight-item">
                    <p><span class="pp-highlight-item-title">Carbon dioxide ice</span> — Near-surface deposits of massive
                    CO₂ ice were discovered within the south polar layered deposits at
                    Australe Mensa, containing sufficient mass to more than double
                    atmospheric pressure if sublimated.</p>
                    <p class="pp-reference">
                        Phillips, R. J. et al. (2011).
                        <strong>Massive CO2 ice deposits sequestered in the south polar layered deposits of Mars.</strong>
                        <em>Science</em>, 332(6031), 838-841.
                        <a href="https://doi.org/10.1126/science.1203091" target="_blank" rel="noopener noreferrer">https://doi.org/10.1126/science.1203091</a>
                    </p>
                </li>
                <li class="pp-highlight-item">
                    <p><span class="pp-highlight-item-title">Mid-latitude glaciers</span> — Strong basal reflections and
                    low dielectric loss confirm that lobate debris aprons are ice-rich,
                    debris-covered glaciers, with hundreds of meters of nearly pure ice
                    beneath thin debris layers.</p>
                    <p class="pp-reference">
                        Holt, J. W. et al. (2008).
                        <strong>Radar sounding evidence for buried glaciers in the southern mid-latitudes of Mars.</strong>
                        <em>Science</em>, 322(5905), 1235-1238.
                        <a href="https://doi.org/10.1126/science.1164246" target="_blank" rel="noopener noreferrer">https://doi.org/10.1126/science.1164246</a>
                    </p>
                </li>
                <li class="pp-highlight-item">
                    <p><span class="pp-highlight-item-title">Subsurface water ice mapping</span> — SHARAD surface and
                    subsurface reflections have been combined with neutron and thermal
                    spectrometer data to map the consistency of near-surface ice across
                    the Martian mid-latitudes, informing future human landing site planning.</p>
                    <p class="pp-reference">
                        Morgan, G. A. et al. (2021).
                        <strong>Availability of subsurface water-ice resources in the northern mid-latitudes of Mars.</strong>
                        <em>Nature Astronomy</em>, 5(3), 230-236.
                        <a href="https://doi.org/10.1038/s41550-020-01290-z" target="_blank" rel="noopener noreferrer">https://doi.org/10.1038/s41550-020-01290-z</a>
                    </p>
                    <p class="pp-reference">
                        Subsurface Water Ice Mapping. (n.d.). Subsurface Water Ice Mapping on Mars. Planetary Science Institute.
                        <a href="https://swim.psi.edu/" target="_blank" rel="noopener noreferrer">https://swim.psi.edu/</a>
                    </p>
                </li>
                <li class="pp-highlight-item">
                    <p><span class="pp-highlight-item-title">Volcanic stratigraphy</span> — Stacks of lava
                    flows have been mapped in Elysium Planitia and other Amazonian volcanic
                    provinces, constraining eruption sources and preexisting terrain
                    morphology.</p>
                    <p class="pp-reference">
                        Morgan, G. A. et al. (2015).
                        <strong>Evidence for the episodic erosion of the Medusae Fossae Formation preserved within the youngest volcanic province on Mars.</strong>
                        <em>Geophysical Research Letters</em>, 42(18), 7336-7342.
                        <a href="https://doi.org/10.1002/2015GL065017" target="_blank" rel="noopener noreferrer">https://doi.org/10.1002/2015GL065017</a>
                    </p>
                </li>
                <li class="pp-highlight-item">
                    <p><span class="pp-highlight-item-title">Ionospheric sounding</span> — SHARAD signals are measurably
                    affected by the Martian ionosphere, enabling mapping of total electron
                    content variability in space and time, including the influence of
                    crustal remanent magnetic fields.</p>
                    <p class="pp-reference">
                        Campbell, B. A. et al. (2024).
                        <strong>SHARAD mapping of Mars dayside ionosphere patterns: Relationship to regional geology and the magnetic field.</strong>
                        <em>Geophysical Research Letters</em>, 51(4), e2023GL105758.
                        <a href="https://doi.org/10.1029/2023GL105758" target="_blank" rel="noopener noreferrer">https://doi.org/10.1029/2023GL105758</a>
                    </p>
                </li>
            </ul>
        </section>

        <!-- References -->
        <section id="references" class="pp-docs-section">
            <h2>References</h2>
            <p class="pp-reference">
                Putzig, N.E. et al. (2024).
                Science results from sixteen years of MRO SHARAD operations.
                <em>Icarus</em>, 419, 115715.
                <a href="https://doi.org/10.1016/j.icarus.2023.115715" target="_blank" rel="noopener noreferrer">https://doi.org/10.1016/j.icarus.2023.115715</a>
            </p>
            <p class="pp-reference">
                Seu, R. et al. (2007).
                SHARAD sounding radar on the Mars Reconnaissance Orbiter.
                <em>Journal of Geophysical Research: Planets</em>, 112, E05S05.
                <a href="https://doi.org/10.1029/2006JE002745" target="_blank" rel="noopener noreferrer">https://doi.org/10.1029/2006JE002745</a>
            </p>
        </section>

    </div>
</div>

<script>
// Scrollspy: highlight the active sidebar link as the user scrolls
const sections = document.querySelectorAll('section[id]');
const navLinks = document.querySelectorAll('#docs-nav .pp-docs-nav-link');
window.addEventListener('scroll', () => {
    let current = '';
    sections.forEach(s => {
        if (window.scrollY >= s.offsetTop - 120) current = s.getAttribute('id');
    });
    navLinks.forEach(l => {
        l.classList.remove('active');
        if (l.getAttribute('href') === '#' + current) l.classList.add('active');
    });
});
</script>

<?php close_layout(); ?>