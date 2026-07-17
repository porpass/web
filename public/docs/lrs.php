<?php
/**
 * lrs.php — SELENE LRS instrument documentation.
 *
 * Covers overview, technical specifications, instrument design,
 * selected science highlights, and references for the Lunar Radar
 * Sounder (LRS) onboard JAXA's SELENE/Kaguya spacecraft.
 */

require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/layout.php';

session_start_secure();
require_login();

open_layout('LRS — Documentation');
?>

<div class="pp-docs-layout">

    <!-- ── Sidebar ───────────────────────────────────────────────────────── -->
    <aside class="pp-docs-sidebar">
        <nav id="docs-nav" class="pp-docs-nav">
            <a class="pp-docs-nav-back" href="/docs.php">← All documentation</a>
            <hr class="pp-docs-nav-divider">
            <p class="pp-docs-nav-label">SELENE LRS</p>
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
        <h1 class="pp-page-title-large">SELENE LRS</h1>
        <p class="pp-lead" style="margin-top: 0.5rem; margin-bottom: 1.5rem;">
            Lunar Radar Sounder &mdash; SELENE/Kaguya (JAXA)
        </p>

        <div class="pp-link-row">
            <a href="https://www.kaguya.jaxa.jp/en/equipment/lrs_e.htm"
               target="_blank" rel="noopener noreferrer"
               class="pp-link-btn">JAXA/Kaguya ↗</a>
            <a href="https://darts.isas.jaxa.jp/planet/pdap/selene/"
               target="_blank" rel="noopener noreferrer"
               class="pp-link-btn">DARTS archive ↗</a>
        </div>

        <!-- Overview -->
        <section id="overview" class="pp-docs-section">
            <h2>Overview</h2>
            <p>
                The Lunar Radar Sounder (LRS) was a scientific instrument onboard
                the Selenological and Engineering Explorer (SELENE) spacecraft
                (also known as Kaguya), a JAXA lunar orbiter
                launched on September 14, 2007. LRS began radar sounder operations
                on October 29, 2007. The mission concluded with a controlled spacecraft
                impact on June 10, 2009.
            </p>
            <p>
                LRS is a frequency-modulated continuous-wave (FMCW) radar sounder
                designed to probe the subsurface structure of the Moon at depths of
                up to approximately 5 km. Operating from a circular polar orbit at an
                altitude of approximately 100 km, LRS performed near-global coverage
                of the lunar surface, acquiring 2,363 hours of radar sounder data and
                8,961 hours of natural radio and plasma wave data over the course of
                the mission (Ono et al., 2010).
            </p>
            <p>
                LRS is the only orbital radar sounder to have operated at the Moon
                since the Apollo Lunar Sounder Experiment (ALSE) during the Apollo 17
                mission in 1972. Compared to ALSE, LRS achieves improved range
                resolution (75 m vs. 280 m free-space in the ALSE HF1 band), employs
                full digital signal processing, and provides near-global coverage
                enabled by SELENE's polar orbit.
            </p>
        </section>

        <!-- Technical specs -->
        <section id="specs" class="pp-docs-section">
            <h2>Technical specifications</h2>
            <h3>Sounder (SDR)</h3>
            <table class="pp-spec-table">
                <tbody>
                    <tr><th>Platform</th><td>SELENE/Kaguya (JAXA)</td></tr>
                    <tr><th>Target body</th><td>Moon</td></tr>
                    <tr><th>Technique</th><td>FMCW chirp</td></tr>
                    <tr><th>Frequency range</th><td>4–6 MHz (nominal)<br>14–16 MHz, 1 MHz (optional)</td></tr>
                    <tr><th>Bandwidth</th><td>2 MHz</td></tr>
                    <tr><th>Sweep rate</th><td>10 kHz/µs</td></tr>
                    <tr><th>Pulse width</th><td>200 µs</td></tr>
                    <tr><th>PRF (SDR-W)</th><td>20 Hz</td></tr>
                    <tr><th>PRF (SDR-A)</th><td>2.5 Hz</td></tr>
                    <tr><th>TX power</th><td>800 W</td></tr>
                    <tr><th>Range resolution</th><td>75 m (free-space)<br>~37.5 m in ε = 4 medium</td></tr>
                    <tr><th>Max sounding depth</th><td>~5 km</td></tr>
                    <tr><th>Sampling rate</th><td>6.25 MSPS</td></tr>
                    <tr><th>Sampling accuracy</th><td>12 bits</td></tr>
                    <tr><th>Orbital altitude</th><td>~100 km</td></tr>
                    <tr><th>Operations</th><td>Oct 2007 – Jun 2009</td></tr>
                </tbody>
            </table>
        </section>

        <!-- Instrument design -->
        <section id="design" class="pp-docs-section">
            <h2>Instrument design</h2>

            <h3>FMCW sounder (SDR)</h3>
            <p>
                LRS employs a frequency-modulated continuous-wave (FMCW) technique.
                The transmitted signal is swept linearly from 4 MHz to 6 MHz over a
                200-µs pulse width, at a sweep rate of 10 kHz/µs. A synchronized swept
                local signal is mixed with received echoes to convert the time delay of
                each echo into a proportional intermediate frequency, which is then
                analyzed by Fast Fourier Transform (FFT) — on the ground after downlinking raw waveforms (Ono &amp; Oya, 2000).
                This range compression technique allows a long, high-power pulse to be
                used while achieving the range resolution set by the 2-MHz bandwidth
                rather than the pulse duration.
            </p>
            <p>
                The free-space range resolution is 75 m, equivalent to approximately
                37.5 m in a medium with relative permittivity ε = 4, representative
                of typical lunar regolith (Ono et al., 2010). A maximum theoretical
                sounding depth of approximately 5 km is achieved for materials with
                loss tangent tan δ ≤ 0.01.
            </p>
            <p>
                A sine-shaped envelope is applied to the transmitted pulse to suppress
                spurious sidelobes in the FFT spectrum, enabling detection of subsurface
                echoes as weak as −50 dB relative to the surface return. This envelope
                reduces transmit power by approximately 2 dB but is essential for
                discriminating weak subsurface echoes from the intense surface reflection.
            </p>

            <h3>Observation modes</h3>
            <div class="pp-table-wrap" style="margin-bottom: 1rem;">
                <table class="pp-table">
                    <thead>
                        <tr>
                            <th>Mode</th>
                            <th>PRF</th>
                            <th>Data type</th>
                            <th>Interval</th>
                            <th>Notes</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr><td>SDR-W</td><td>20 Hz</td><td>IF waveform</td><td>50 ms</td><td>Primary sounder mode</td></tr>
                        <tr><td>SDR-A</td><td>2.5 Hz</td><td>IF waveform</td><td>400 ms</td><td>Low data-rate mode</td></tr>
                    </tbody>
                </table>
            </div>

            <h3>Passive receivers (NPW &amp; WFC)</h3>
            <p>
                In addition to the active sounder, LRS includes two passive subsystems.
                The Natural Plasma Wave (NPW) receiver is a sweep-frequency analyzer
                covering 20 kHz to 30 MHz in 512 frequency steps across four bands,
                with a sweep time of 2 seconds. The Waveform Capture (WFC) receiver
                consists of two sub-receivers: WFC-H (1 kHz – 1 MHz, fast-sweep
                frequency analyzer) and WFC-L (100 Hz – 100 kHz, direct waveform
                capture). Together, these subsystems cover a continuous frequency range
                from 100 Hz to 30 MHz, enabling observations of electron plasma waves,
                electrostatic solitary waves, auroral kilometric radiation, and planetary
                radio emissions (Ono et al., 2010).
            </p>

            <h3>Antenna system</h3>
            <p>
                LRS employs four antenna elements (LRS-A1 through A4), each 15 m long
                and made of BeCu alloy, forming two orthogonal 30 m tip-to-tip cross
                dipole antennas. The crossed dipole configuration enables polarization
                measurements of natural radio and plasma waves. Because KAGUYA is
                three-axis stabilized rather than spin-stabilized, rigid Bi-Stem antenna
                elements were used in place of deployable wire antennas. The antenna
                plane is maintained facing the lunar surface by spacecraft attitude
                control. Elements were deployed sequentially following lunar orbit
                insertion due to power supply limitations.
            </p>
        </section>

        <!-- Science highlights -->
        <section id="science" class="pp-docs-section">
            <h2>Selected science highlights</h2>
            <ul class="pp-highlight-list">
                <li class="pp-highlight-item">
                    <p><span class="pp-highlight-item-title">Nearside mare stratigraphy</span> — Distinct subsurface
                    reflectors at apparent depths of several hundred meters were detected
                    in multiple nearside maria, interpreted as buried regolith layers
                    covered by basalt lava flows. The presence of subsurface reflectors
                    rather than off-nadir surface echoes was confirmed by comparing
                    radargrams across different orbital longitudes.</p>
                    <p class="pp-reference">
                        Ono, T. et al. (2009).
                        <strong>Lunar radar sounder observations of subsurface layers under the nearside maria of the Moon.</strong>
                        <em>Science</em>, 323(5916), 909-912.
                        <a href="https://doi.org/10.1126/science.1165988" target="_blank" rel="noopener noreferrer">https://doi.org/10.1126/science.1165988</a>
                    </p>
                </li>
                <li class="pp-highlight-item">
                    <p><span class="pp-highlight-item-title">Mare ridge tectonics</span> — Subsurface data from Mare
                    Serenitatis revealed that mare ridges are surface manifestations of
                    anticlines formed by compressional deformation of stacked basalt
                    flows. The absence of growth structures in the folded layers indicates
                    post-depositional deformation younger than approximately 2.84 Ga.</p>
                    <p class="pp-reference">
                        Ono, T. et al. (2009).
                        <strong>Lunar radar sounder observations of subsurface layers under the nearside maria of the Moon.</strong>
                        <em>Science</em>, 323(5916), 909-912.
                        <a href="https://doi.org/10.1126/science.1165988" target="_blank" rel="noopener noreferrer">https://doi.org/10.1126/science.1165988</a>
                    </p>
                </li>
                <li class="pp-highlight-item">
                    <p><span class="pp-highlight-item-title">TiO₂ echo masking</span> — Analysis of regions with and
                    without clear subsurface echoes found a clear anti-correlation with
                    TiO₂-rich surface areas. High-ilmenite content increases the loss
                    tangent of surface materials, attenuating subsurface returns and
                    creating apparent gaps in subsurface echo coverage.</p>
                    <p class="pp-reference">
                        Pommerol, A. et al. (2010).
                        <strong>Detectability of subsurface interfaces in lunar maria by the LRS/SELENE sounding radar: Influence of mineralogical composition.</strong>
                        <em>Geophysical Research Letters</em>, 37(3).
                        <a href="https://doi.org/10.1029/2009GL041681" target="_blank" rel="noopener noreferrer">https://doi.org/10.1029/2009GL041681</a>
                    </p>
                </li>
                <li class="pp-highlight-item">
                    <p><span class="pp-highlight-item-title">Near-global coverage</span> — LRS achieved radar sounder
                    coverage of nearly the entire lunar surface — including farside
                    highlands and polar regions — across 2,363 hours of operation,
                    producing the most comprehensive lunar subsurface radar dataset since
                    ALSE. SDR-W data with 50-ms time resolution were obtained over most
                    of the lunar surface, enabling SAR analysis.</p>
                    <p class="pp-reference">
                        Ono, T. et al. (2010).
                        <strong>The Lunar Radar Sounder (LRS) Onboard the KAGUYA (SELENE) Spacecraft.</strong>
                        <em>Space Science Reviews</em>, 154(1), 145-192.
                        <a href="https://doi.org/10.1007/s11214-010-9673-8" target="_blank" rel="noopener noreferrer">https://doi.org/10.1007/s11214-010-9673-8</a>
                    </p>
                </li>
                <li class="pp-highlight-item">
                    <p><span class="pp-highlight-item-title">Auroral kilometric radiation</span> — Passive observations
                    detected AKR from Earth with interference patterns caused by
                    reflections off the lunar surface, enabling a new method for probing
                    lunar surface reflectivity and searching for evidence of a lunar
                    ionosphere via ray-tracing analysis of the interference pattern.</p>
                    <p class="pp-reference">
                        Goto, Y. et al. (2011).
                        <strong>Lunar ionosphere exploration method using auroral kilometric radiation.</strong>
                        <em>Earth, Planets and Space</em>, 63(1), 47-56.
                        <a href="https://doi.org/10.5047/eps.2011.01.005" target="_blank" rel="noopener noreferrer">https://doi.org/10.5047/eps.2011.01.005</a>
                    </p>
                </li>
                <li class="pp-highlight-item">
                    <p><span class="pp-highlight-item-title">Plasma environment of the lunar wake</span> — WFC
                    observations revealed electrostatic solitary waves (ESW) and electron
                    plasma oscillations around the lunar wake boundary, providing direct
                    in-situ electron density measurements and characterizing the complex
                    plasma environment on the lunar dayside and in the wake region.</p>
                    <p class="pp-reference">
                        Hashimoto, K. et al. (2010).
                        <strong>Electrostatic solitary waves associated with magnetic anomalies and wake boundary of the Moon observed by KAGUYA.</strong>
                        <em>Geophysical Research Letters</em>, 37(19).
                        <a href="https://doi.org/10.1029/2010GL044529" target="_blank" rel="noopener noreferrer">https://doi.org/10.1029/2010GL044529</a>
                    </p>
                </li>
            </ul>
        </section>

        <!-- References -->
        <section id="references" class="pp-docs-section">
            <h2>References</h2>
            <p class="pp-reference">
                Ono, T. and Oya, H. (2000).
                Lunar Radar Sounder (LRS) experiment on-board the SELENE spacecraft.
                <em>Earth, Planets and Space</em>, 52, 629–637.
                <a href="https://doi.org/10.1186/BF03352248" target="_blank" rel="noopener noreferrer">https://doi.org/10.1186/BF03352248</a>
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