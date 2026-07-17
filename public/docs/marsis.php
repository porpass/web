<?php
/**
 * marsis.php — MEx MARSIS instrument documentation.
 *
 * Covers overview, technical specifications, instrument design,
 * selected science highlights, and references for the Mars Advanced
 * Radar for Subsurface and Ionosphere Sounding (MARSIS) onboard
 * ESA's Mars Express spacecraft.
 */

require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/layout.php';

session_start_secure();
require_login();

open_layout('MARSIS — Documentation');
?>

<div class="pp-docs-layout">

    <!-- ── Sidebar ───────────────────────────────────────────────────────── -->
    <aside class="pp-docs-sidebar">
        <nav id="docs-nav" class="pp-docs-nav">
            <a class="pp-docs-nav-back" href="/docs.php">← All documentation</a>
            <hr class="pp-docs-nav-divider">
            <p class="pp-docs-nav-label">MEx MARSIS</p>
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
        <h1 class="pp-page-title-large">MEx MARSIS</h1>
        <p class="pp-lead" style="margin-top: 0.5rem; margin-bottom: 1.5rem;">
            Mars Advanced Radar for Subsurface and Ionosphere Sounding &mdash; Mars Express (ESA)
        </p>

        <div class="pp-link-row">
            <a href="https://www.esa.int/Science_Exploration/Space_Science/Mars_Express/Mars_Express_orbiter_instruments"
               target="_blank" rel="noopener noreferrer"
               class="pp-link-btn">ESA/MARSIS ↗</a>
            <a href="https://pds-geosciences.wustl.edu/missions/mars_express/marsis.htm"
               target="_blank" rel="noopener noreferrer"
               class="pp-link-btn">PDS archive ↗</a>
        </div>

        <!-- Overview -->
        <section id="overview" class="pp-docs-section">
            <h2>Overview</h2>
            <p>
                The Mars Advanced Radar for Subsurface and Ionosphere Sounding
                (MARSIS) is a low-frequency, dual-channel radar sounder onboard the
                European Space Agency's (ESA) Mars Express (MEx) spacecraft.
                Developed by an Italian–US team, MARSIS is the first spaceborne
                sounding radar to operate at Mars and the first instrument of its
                kind since the Apollo Lunar Sounder Experiment (ALSE) in 1972.
                MARSIS began acquiring scientific data in July 2005 following
                successful deployment of its 40-m antenna (Orosei et al., 2015).
            </p>
            <p>
                MARSIS operates from a highly elliptical orbit, acquiring subsurface
                sounding data at altitudes below 900 km and ionospheric sounding data
                at altitudes up to 1200 km. The instrument transmits low-frequency
                radio pulses through a 40-m dipole antenna. A second 7-m
                monopole antenna provides a surface clutter cancellation channel.
                The subsurface sounder operates over four frequency bands of 1 MHz
                bandwidth each in the range 1.3–5.5 MHz, with the bands centered at
                3, 4, and 5 MHz most commonly used for subsurface sounding
                (Jordan et al., 2009).
            </p>
            <p>
                MARSIS data are complementary to SHARAD: operating at lower
                frequencies with a narrower bandwidth, MARSIS achieves greater
                penetration depth — up to several kilometers in ice-rich polar
                terrains — at the cost of coarser vertical resolution. The nominal
                free-space range resolution is 150 m, compared to SHARAD's 15 m. MARSIS
                also successfully sounded Phobos during several close flybys of Mars
                Express, becoming the first radar sounder to observe an asteroid-like
                body (Orosei et al., 2015).
            </p>
        </section>

        <!-- Technical specs -->
        <section id="specs" class="pp-docs-section">
            <h2>Technical specifications</h2>
            <h3>Subsurface sounder</h3>
            <table class="pp-spec-table">
                <tbody>
                    <tr><th>Platform</th><td>Mars Express (ESA)</td></tr>
                    <tr><th>Target body</th><td>Mars, Phobos</td></tr>
                    <tr><th>Frequency range</th><td>1.3–5.5 MHz (4 bands)</td></tr>
                    <tr><th>Bandwidth per band</th><td>1 MHz</td></tr>
                    <tr><th>Pulse width</th><td>250 µs</td></tr>
                    <tr><th>PRF</th><td>127 Hz</td></tr>
                    <tr><th>Peak TX power</th><td>1.5–5 W</td></tr>
                    <tr><th>Range resolution</th><td>150 m (free-space)</td></tr>
                    <tr><th>Dynamic range</th><td>40–50 dB</td></tr>
                    <tr><th>Depth window</th><td>~15 km (nominal)</td></tr>
                    <tr><th>Dipole antenna</th><td>40 m tip-to-tip</td></tr>
                    <tr><th>Monopole antenna</th><td>7 m</td></tr>
                    <tr><th>SS altitude range</th><td>250–900 km</td></tr>
                    <tr><th>Operations</th><td>Jun 2005 – present</td></tr>
                </tbody>
            </table>
        </section>

        <!-- Instrument design -->
        <section id="design" class="pp-docs-section">
            <h2>Instrument design</h2>
            <p>
                MARSIS consists of four subsystems: a sounder channel with a
                programmable signal generator, a 40-m dipole antenna transmitter and
                receiver; a surface clutter cancellation channel using a 7-m monopole
                antenna and second receiver; a dual-channel data processor; and a
                digital electronics and power control subsystem (Jordan et al., 2009).
                The monopole antenna has a null in the nadir direction and is intended
                to receive only off-nadir surface returns, enabling cross-track clutter
                cancellation in ground processing — though elevated noise in this
                channel has in practice limited its use.
            </p>

            <h3>Subsurface sounding modes</h3>
            <p>
                MARSIS has five subsurface sounding modes (SS1–SS5), differing in the
                number of frequency bands, antennas, and Doppler filters downlinked.
                Mode SS3 — two frequency bands, dipole antenna only, three Doppler
                filters per frame — has been the most widely used because it preserves
                complex I/Q data for flexible ground processing. Mode SS2 applies
                onboard non-coherent multilook integration and downlinks a single
                amplitude profile per frame, reducing data volume at the cost of
                flexibility. Due to excessive noise in the monopole channel, modes
                requiring cross-track clutter cancellation (SS1, SS4) have seen limited
                use in practice (Jordan et al., 2009).
            </p>
            <div class="pp-table-wrap" style="margin-bottom: 1rem;">
                <table class="pp-table">
                    <thead>
                        <tr>
                            <th>Mode</th>
                            <th>Freq. bands</th>
                            <th>Antenna</th>
                            <th>Doppler filters</th>
                            <th>Range processing</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr><td>SS1</td><td>2</td><td>Dipole + Monopole</td><td>1</td><td>Ground</td></tr>
                        <tr><td>SS2</td><td>2</td><td>Dipole only</td><td>1 (multilook onboard)</td><td>Onboard</td></tr>
                        <tr><td>SS3</td><td>2</td><td>Dipole only</td><td>3</td><td>Ground</td></tr>
                        <tr><td>SS4</td><td>1</td><td>Dipole + Monopole</td><td>5</td><td>Ground</td></tr>
                        <tr><td>SS5</td><td>1</td><td>Dipole + Monopole</td><td>3 (short pulse)</td><td>Ground</td></tr>
                    </tbody>
                </table>
            </div>

            <h3>Key processing challenges</h3>
            <p>
                The primary processing challenge for MARSIS subsurface data is
                ionospheric defocusing — plasma in the Martian ionosphere acts as a
                dispersive medium, causing frequency-dependent propagation delays that
                broaden the received pulse and degrade both signal-to-noise ratio and
                range resolution. This effect is most severe during periods of high
                solar activity, when it can render data unintelligible. Several
                correction algorithms have been developed, including the contrast method
                used for publicly-available PDS products (Orosei et al., 2015).
                A useful byproduct of this correction is an estimate of the ionospheric
                total electron content (TEC) along the propagation path.
            </p>
        </section>

        <!-- Science highlights -->
        <section id="science" class="pp-docs-section">
            <h2>Selected science highlights</h2>
            <ul class="pp-highlight-list">
                <li class="pp-highlight-item">
                    <p><span class="pp-highlight-item-title">North polar layered deposits</span> — Early MARSIS observations
                    of Planum Boreum detected the base of the north polar layered deposits
                    (NPLD) and the underlying Basal Unit, confirming the predominantly icy
                    composition of the NPLD (dielectric constant consistent with nearly
                    pure water ice). Modeling using the observations also revealed an absence
                    of lithospheric deflection, implying a thick elastic lithosphere beneath
                    the north pole.</p>
                    <p class="pp-reference">
                        Selvans, M. M. et al. (2010).
                        <strong>Internal structure of Planum Boreum, from Mars advanced radar for subsurface and ionospheric sounding data.</strong>
                        <em>Journal of Geophysical Research: Planets</em>, 115(E9).
                        <a href="https://doi.org/10.1029/2009JE003537" target="_blank" rel="noopener noreferrer">https://doi.org/10.1029/2009JE003537</a>
                    </p>
                    <p class="pp-reference">
                        Picardi, G. et al. (2005).
                        <strong>Radar soundings of the subsurface of Mars.</strong>
                        <em>Science</em>, 310(5756), 1925-1928.
                        <a href="https://doi.org/10.1126/science.1122165" target="_blank" rel="noopener noreferrer">https://doi.org/10.1126/science.1122165</a>
                    </p>
                </li>
                <li class="pp-highlight-item">
                    <p><span class="pp-highlight-item-title">South polar layered deposits</span> — MARSIS mapped the
                    thickness and extent of the south polar layered deposits (SPLD),
                    estimating a total volume of approximately 1.6 × 10⁶ km³ — equivalent
                    to a global water layer of 11 ± 1.4 m. Regions of anomalously bright
                    basal reflections are consistent with a 10–100 m overlying CO₂ ice
                    layer.</p>
                    <p class="pp-reference">
                        Plaut, J. J. et al. (2007).
                        <strong>Subsurface radar sounding of the south polar layered deposits of Mars.</strong>
                        <em>Science</em>, 316(5821), 92-95.
                        <a href="https://doi.org/10.1126/science.1139672" target="_blank" rel="noopener noreferrer">https://doi.org/10.1126/science.1139672</a>
                    </p>
                </li>
                <li class="pp-highlight-item">
                    <p><span class="pp-highlight-item-title">Vastitas Borealis Formation</span> — Global surface
                    reflectivity mapping revealed low dielectric constants across the
                    Vastitas Borealis Formation, consistent with ice-rich material to at
                    least several tens of meters depth — possibly the sublimation residue
                    of a late Hesperian ocean fed by outflow channels approximately 3 Ga.</p>
                    <p class="pp-reference">
                        Mouginot, J. et al. (2012).
                        <strong>Dielectric map of the Martian northern hemisphere and the nature of plain filling materials.</strong>
                        <em>Geophysical Research Letters</em>, 39(2).
                        <a href="https://doi.org/10.1029/2011GL050286" target="_blank" rel="noopener noreferrer">https://doi.org/10.1029/2011GL050286</a>
                    </p>
                </li>
                <li class="pp-highlight-item">
                    <p><span class="pp-highlight-item-title">Medusae Fossae Formation</span> — MARSIS characterized the
                    dielectric properties of the MFF, finding a bulk dielectric constant
                    of approximately 2.9 ± 0.4 and loss tangent of 0.002–0.006, consistent
                    with either low-density unconsolidated volcanic material or an ice-rich
                    deposit.</p>
                    <p class="pp-reference">
                        Watters, T. R. et al. (2007).
                        <strong>Radar sounding of the Medusae Fossae Formation Mars: equatorial ice or dry, low-density deposits?</strong>
                        <em>Science</em>, 318(5853), 1125-1128.
                        <a href="https://doi.org/10.1126/science.1148112" target="_blank" rel="noopener noreferrer">https://doi.org/10.1126/science.1148112</a>
                    </p>
                </li>
                <li class="pp-highlight-item">
                    <p><span class="pp-highlight-item-title">Ionospheric structure</span> — Active ionospheric sounding
                    has revealed the complex interplay between the Martian ionosphere,
                    crustal remanent magnetic fields, and the solar wind — including
                    three-dimensional plasma structures, a transient Martian ionopause
                    (occurring ~20% of the time), magnetic flux ropes, and the response
                    of the ionosphere to solar energetic particle events and coronal mass
                    ejections.</p>
                    <p class="pp-reference">
                        Gurnett, D. A. et al. (2005).
                        <strong>Radar soundings of the ionosphere of Mars.</strong>
                        <em>Science</em>, 310(5756), 1929-1933.
                        <a href="https://doi.org/10.1126/science.1121868" target="_blank" rel="noopener noreferrer">https://doi.org/10.1126/science.1121868</a>
                    </p>
                    <p class="pp-reference">
                        Duru, F. et al. (2006).
                        <strong>Magnetically controlled structures in the ionosphere of Mars.</strong>
                        <em>Journal of Geophysical Research: Space Physics</em>, 111(A12).
                        <a href="https://doi.org/10.1029/2006JA011975" target="_blank" rel="noopener noreferrer">https://doi.org/10.1029/2006JA011975</a>
                    </p>
                    <p class="pp-reference">
                        Duru, F. et al. (2009).
                        <strong>Steep, transient density gradients in the Martian ionosphere similar to the ionopause at Venus.</strong>
                        <em>Journal of Geophysical Research: Space Physics</em>, 114(A12).
                        <a href="https://doi.org/10.1029/2009JA014711" target="_blank" rel="noopener noreferrer">https://doi.org/10.1029/2009JA014711</a>
                    </p>
                    <p class="pp-reference">
                        Morgan, D. D. et al. (2011).
                        <strong>Dual-spacecraft observation of large-scale magnetic flux ropes in the Martian ionosphere.</strong>
                        <em>Journal of Geophysical Research: Space Physics</em>, 116(A2).
                        <a href="https://doi.org/10.1029/2010JA016134" target="_blank" rel="noopener noreferrer">https://doi.org/10.1029/2010JA016134</a>
                    </p>
                    <p class="pp-reference">
                        Harada, Y. et al. (2018).
                        <strong>MARSIS observations of the Martian nightside ionosphere during the September 2017 solar event.</strong>
                        <em>Geophysical Research Letters</em>, 45(16), 7960-7967.
                        <a href="https://doi.org/10.1002/2018GL077622" target="_blank" rel="noopener noreferrer">https://doi.org/10.1002/2018GL077622</a>
                    </p>
                </li>
                <li class="pp-highlight-item">
                    <p><span class="pp-highlight-item-title">Phobos</span> — MARSIS successfully sounded Phobos during
                    close flybys of Mars Express with signal-to-noise ratios of ~25 dB,
                    becoming the first radar sounder to observe an asteroid-like body.
                    Dielectric variations across the Phobos surface were detected in
                    simulation comparisons, though no subsurface interfaces have been
                    positively identified.</p>
                    <p class="pp-reference">
                        Cicchetti, A. et al. (2017).
                        <strong>Observations of Phobos by the Mars Express radar MARSIS: Description of the detection techniques and preliminary results.</strong>
                        <em>Science</em>, 310(5756), 1929-1933.
                        <a href="https://doi.org/10.1016/j.asr.2017.08.013" target="_blank" rel="noopener noreferrer">https://doi.org/10.1016/j.asr.2017.08.013</a>
                    </p>
                </li>
            </ul>
        </section>

        <!-- References -->
        <section id="references" class="pp-docs-section">
            <h2>References</h2>
            <p class="pp-reference">
                Jordan, R. et al. (2009).
                The Mars Express MARSIS sounder instrument.
                <em>Planetary and Space Science</em>, 57, 1975–1986.
                <a href="https://doi.org/10.1016/j.pss.2009.09.016" target="_blank" rel="noopener noreferrer">https://doi.org/10.1016/j.pss.2009.09.016</a>
            </p>
            <p class="pp-reference">
                Orosei, R. et al. (2015).
                Mars Advanced Radar for Subsurface and Ionospheric Sounding (MARSIS) after nine years of operation: A summary.
                <em>Planetary and Space Science</em>, 112, 98–114.
                <a href="https://doi.org/10.1016/j.pss.2014.07.010" target="_blank" rel="noopener noreferrer">https://doi.org/10.1016/j.pss.2014.07.010</a>
            </p>
        </section>

    </div>
</div>

<script>
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