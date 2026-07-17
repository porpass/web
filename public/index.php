<?php
/**
 * index.php — PORPASS public landing page.
 *
 * Displays project information, supported instruments, and team details.
 * Authenticated users are redirected to the dashboard automatically.
 */

require_once __DIR__ . '/../src/auth.php';

session_start_secure();

if (is_logged_in()) {
    header('Location: /dashboard.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PORPASS: The Planetary Orbital Radar Processing and Simulation System</title>
    <link href="/resources/css/bootstrap.min.css" rel="stylesheet">
    <link href="/resources/css/porpass.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
</head>
<body>

<!-- ── Navbar ─────────────────────────────────────────────────────────────── -->
<nav class="pp-navbar">
    <div class="container">
        <a class="pp-nav-brand" href="/index.php">
            <svg class="pp-nav-waveform" width="44" height="24" viewBox="0 0 44 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                <polyline points="0,12 6,12 9,4 13,20 17,8 21,14 24,12 30,12" stroke="#1D9E75" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                <polyline points="4,15 8,15 10,19 14,11 18,16 22,13 25,15 30,15" stroke="#EF9F27" stroke-width="1.1" stroke-linecap="round" stroke-linejoin="round" opacity="0.8"/>
            </svg>
            <span class="pp-nav-wordmark">PORPASS</span>
        </a>
        <a class="pp-nav-signin" href="/login.php">Sign In</a>
    </div>
</nav>

<!-- ── Hero ───────────────────────────────────────────────────────────────── -->
<header class="pp-hero">
    <div class="container pp-hero-inner">
        <!-- Full wordmark SVG -->
        <svg class="pp-hero-logo" viewBox="0 0 680 160" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="PORPASS">
            <polyline points="52,72 72,72 79,55 87,91 95,65 102,80 109,72 129,72"
                fill="none" stroke="#1D9E75" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/>
            <polyline points="66,82 74,82 80,91 88,72 96,84 103,77 110,82 124,82"
                fill="none" stroke="#EF9F27" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round" opacity="0.8"/>
            <text x="340" y="82" text-anchor="middle"
                font-family="'DM Sans', sans-serif" font-size="44" font-weight="500"
                letter-spacing="7" fill="#5DCAA5">PORPASS</text>
            <polyline points="551,72 571,72 578,80 585,65 593,91 601,55 609,72 628,72"
                fill="none" stroke="#1D9E75" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/>
            <polyline points="556,82 570,82 577,77 584,84 592,72 600,91 606,82 614,82"
                fill="none" stroke="#EF9F27" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round" opacity="0.8"/>
            <text x="340" y="114" text-anchor="middle"
                font-family="'DM Sans', sans-serif" font-size="10" letter-spacing="2.2"
                fill="#5DCAA5" opacity="0.75">PLANETARY ORBITAL RADAR PROCESSING &amp; SIMULATION SYSTEM</text>
            <line x1="52"  y1="106" x2="148" y2="106" stroke="#1D9E75" stroke-width="0.5" opacity="0.4"/>
            <line x1="532" y1="106" x2="628" y2="106" stroke="#1D9E75" stroke-width="0.5" opacity="0.4"/>
        </svg>

        <p class="pp-hero-tagline">
            A web application for custom processing and simulation of planetary radar
            sounder data from Mars and the Moon — built to outlast any single mission.
        </p>
        <a href="/register.php" class="pp-btn pp-btn-primary pp-btn-lg">Request an Account</a>
    </div>
</header>
<div class="pp-hero-rule"></div>

<main class="container">

    <!-- ── About ──────────────────────────────────────────────────────────── -->
    <section class="pp-section" id="about">
        <p class="pp-section-label">About</p>
        <h2 class="pp-section-title">Planetary radar, preserved for the next generation</h2>
        <p class="pp-lead">
            PORPASS provides users with a web application designed to facilitate
            custom processing and simulations of planetary radar data. The overarching
            goal of PORPASS is to enhance mission legacy by providing custom processing
            and simulation of planetary radar datasets beyond the life of any particular
            orbiting planetary science mission, ensuring data and code longevity and
            relevance as well as opening the door to the next generation of researchers.
        </p>
        <figure class="pp-figure">
            <img src="/resources/img/SHARAD_Radargram.png" alt="Example SHARAD radargram of Mars' North Polar Layered Deposits">
            <figcaption>Example SHARAD radargram of Mars' North Polar Layered Deposits and surrounding terrain.</figcaption>
        </figure>
    </section>

    <!-- ── Instruments ────────────────────────────────────────────────────── -->
    <section class="pp-section" id="instruments">
        <p class="pp-section-label">Instruments</p>
        <h2 class="pp-section-title">Supported radar sounders</h2>
        <p class="pp-lead">
            For this initial development, we focus our efforts on radar sounding data
            from SHARAD, MARSIS, and LRS.
        </p>
        <div class="pp-card-grid">

            <div class="pp-card">
                <span class="pp-card-tag">MRO · Mars</span>
                <h3 class="pp-card-title">
                    <a href="https://sharad.psi.edu" target="_blank" rel="noopener noreferrer">SHARAD</a>
                </h3>
                <p class="pp-card-body">
                    The Mars Reconnaissance Orbiter Shallow Radar has been collecting
                    information on the surface and subsurface of Mars since late 2006.
                    SHARAD emits a 10-watt chirped pulse downswept from 25 to 15 MHz,
                    yielding a 15-meter range resolution in free-space.
                </p>
            </div>

            <div class="pp-card">
                <span class="pp-card-tag">MEX · Mars</span>
                <h3 class="pp-card-title">
                    <a href="https://mars.nasa.gov/express/mission/sc_science_marsis01.html" target="_blank" rel="noopener noreferrer">MARSIS</a>
                </h3>
                <p class="pp-card-body">
                    The Mars Advanced Radar for Ionosphere and Subsurface Sounding
                    onboard ESA's Mars Express has been observing Mars since 2005.
                    MARSIS operates in various modes; the 1-MHz bands centered at
                    3, 4, and 5 MHz are used for subsurface sounding.
                </p>
            </div>

            <div class="pp-card">
                <span class="pp-card-tag">SELENE · Moon</span>
                <h3 class="pp-card-title">
                    <a href="https://www.kaguya.jaxa.jp/en/equipment/lrs_e.htm" target="_blank" rel="noopener noreferrer">LRS</a>
                </h3>
                <p class="pp-card-body">
                    The Selenological and Engineering Explorer's (Kaguya) Lunar Radar
                    Sounder is a frequency-modulated continuous-wave sounder with a
                    2-MHz bandwidth centered at 5 MHz. LRS was in operation
                    from 2007–2009.
                </p>
            </div>

        </div>
    </section>

    <!-- ── Applications ───────────────────────────────────────────────────── -->
    <section class="pp-section" id="applications">
        <p class="pp-section-label">Applications</p>
        <h2 class="pp-section-title">Processing, simulation, and exploration</h2>
        <p class="pp-lead">
            PORPASS features two main software applications as well as an interactive GIS environment.
        </p>
        <div class="pp-card-grid">

            <div class="pp-card pp-card--app">
                <h3 class="pp-card-title">GRaSP</h3>
                <p class="pp-card-body">
                    The Generalized Radar Sounder Processor is the heart of PORPASS.
                    All radar sounders operate under the same physics regime, allowing
                    one to design a generic processor for any sounder system once the
                    various instrument differences have been accounted for. GRaSP
                    provides SAR processing to enhance along-track resolution and
                    boost the effective signal-to-noise ratio.
                </p>
            </div>

            <div class="pp-card pp-card--app">
                <h3 class="pp-card-title">OaRS</h3>
                <p class="pp-card-body">
                    The Orbital Radar Simulator answers a long-standing issue in
                    planetary radar sounder science: the lack of any publicly-available,
                    open-source full-waveform radar simulator. Developed with the
                    Center of Wave Phenomena at the Colorado School of Mines, OaRS
                    lets users simulate radar data through free-form subsurface
                    environments.
                </p>
            </div>

            <div class="pp-card pp-card--app">
                <h3 class="pp-card-title">PORPASS GIS</h3>
                <p class="pp-card-body">
                    An interactive geographic information system displaying radar
                    ground-tracks over Mars and Moon basemaps. Users can select
                    observations over regions of interest and configure bulk
                    processing parameters directly from the map interface.
                </p>
            </div>

        </div>
    </section>

    <!-- ── Team ───────────────────────────────────────────────────────────── -->
    <section class="pp-section" id="team">
        <p class="pp-section-label">People</p>
        <h2 class="pp-section-title">The PORPASS Team</h2>
        <p class="pp-team-text">
            The PORPASS Project is managed by Matthew R. Perry (PSI) on behalf of
            Principal Investigator Nathaniel Putzig (PSI). Other investigators involved
            in the development of PORPASS include Megan B. Russell (PSI), Gareth Morgan (PSI),
            Frederick Foss (Freestyle Analytical and Quantitative Services, LLC),
            Paul Sava (Colorado School of Mines), Dylan Hickson (Colorado School of Mines),
            Bruce Campbell (Smithsonian Institution), and Andrew Kopf (US Naval Observatory).
        </p>
    </section>

    <!-- ── Account request CTA ────────────────────────────────────────────── -->
    <section class="pp-section" id="access">
        <div class="pp-cta-band">
            <div class="pp-cta-band-text">
                <h3>Request an Account</h3>
                <p>
                    PORPASS is in active development. Access is available to collaborators
                    and researchers — accounts are reviewed and approved by an administrator.
                </p>
            </div>
            <a href="/register.php" class="pp-btn pp-btn-primary">Register</a>
        </div>
    </section>

    <!-- ── Related resources ──────────────────────────────────────────────── -->
    <section class="pp-section" id="resources">
        <p class="pp-section-label">Resources</p>
        <h2 class="pp-section-title">Related links</h2>
        <ul class="pp-resources">
            <li><a href="https://pds.nasa.gov" target="_blank" rel="noopener noreferrer">NASA Planetary Data System (PDS)</a></li>
            <li><a href="https://psi.edu" target="_blank" rel="noopener noreferrer">Planetary Science Institute (PSI)</a></li>
            <li><a href="https://sharad.psi.edu" target="_blank" rel="noopener noreferrer">SHARAD at PSI</a></li>
            <li><a href="https://mines.edu" target="_blank" rel="noopener noreferrer">Colorado School of Mines</a></li>
        </ul>
    </section>

</main>

<!-- ── Footer ─────────────────────────────────────────────────────────────── -->
<footer class="pp-footer">
    <div class="container">
        <div class="pp-footer-inner">
            <div class="pp-footer-logo">
                <img src="/resources/img/PSI_Logo.png" alt="Planetary Science Institute">
            </div>
            <div class="pp-footer-text">
                <p><strong style="color:#E1F5EE;">The Planetary Science Institute</strong></p>
                <p>1700 East Fort Lowell, Suite 106, Tucson, AZ 85719-2395 &mdash; (520) 622-6300</p>
                <p class="pp-footer-small">
                    Development funded by the NASA Planetary Data Archival, Restoration, and Tools
                    (PDART) Program, grant number 80NSSC20K1057.
                </p>
            </div>
        </div>
        <hr class="pp-footer-divider">
        <p class="pp-footer-bottom text-center">
            PORPASS &mdash; Planetary Orbital Radar Processing and Simulation System
        </p>
    </div>
</footer>

<script src="/resources/js/bootstrap.bundle.min.js"></script>
</body>
</html>