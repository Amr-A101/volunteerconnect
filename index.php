<?php 
$page_title = "Welcome";
require_once __DIR__ . '/app/views/layout/header.php';
?>

<link rel="stylesheet" href="/volcon/assets/css/home.css">

<style>
    .vc-animate-on-scroll {
        opacity: 0;
        transition: opacity 1s ease-out, transform 0.8s ease-out;
        transform: translateY(20px);
    }
    .vc-fade-in {
        opacity: 1;
        transform: translateY(0);
    }
</style>

<section class="vc-hero">

    <div class="vc-hero-slides">
        <div class="vc-hero-slide active" style="background-image:url('/volcon/assets/res/volcon-collage.jpg')"></div>
        <div class="vc-hero-slide" style="background-image:url('/volcon/assets/res/home/hero-volunteer_1.jpg')"></div>
        <div class="vc-hero-slide" style="background-image:url('/volcon/assets/res/home/hero-volunteer_2.jpg')"></div>
        <div class="vc-hero-slide" style="background-image:url('/volcon/assets/res/home/hero-volunteer_3.jpg')"></div>
        <div class="vc-hero-slide" style="background-image:url('/volcon/assets/res/home/hero-volunteer_4.jpg')"></div>
        <div class="vc-hero-slide">
            <video autoplay muted loop playsinline class="vc-hero-video">
                <source src="/volcon/assets/res/home/hero-community.mp4" type="video/mp4">
            </video>
        </div>
    </div>

    <div class="vc-hero-overlay"></div>

    <div class="vc-hero-content">
        <h1>Connect. Volunteer. Impact.</h1>
        <p>
            Volunteer Connect is a smart volunteer management platform that
            bridges passionate volunteers with organizations creating real-world impact.
        </p>

        <div class="vc-hero-actions">
            <a href="/volcon/app/signup.php" class="vc-btn vc-btn-primary">Get Started Free</a>
            <a href="#how-it-works" class="vc-btn vc-btn-outline">How It Works</a>
        </div>
    </div>

</section>

<section class="vc-section vc-intro">
    <div class="vc-container">
        <h2>One Platform. Endless Impact.</h2>
        <p class="vc-section-desc">
            Volunteer Connect is designed to manage the entire volunteering lifecycle —
            from discovery and application to engagement, communication, and recognition.
        </p>
    </div>
</section>

<section class="vc-section vc-features">
    <div class="vc-container vc-grid-3">

        <div class="vc-feature-card">
            <i class="fa-solid fa-user-group"></i>
            <h3>Volunteer Management</h3>
            <p>
                Build a volunteer profile with skills, interests, and availability.
                Apply, track, and manage your volunteer journey seamlessly.
            </p>
        </div>

        <div class="vc-feature-card">
            <i class="fa-solid fa-building"></i>
            <h3>Organization Tools</h3>
            <p>
                Create, publish, and manage volunteer opportunities.
                Review applications, communicate with volunteers, and track participation.
            </p>
        </div>

        <div class="vc-feature-card">
            <i class="fa-solid fa-brain"></i>
            <h3>Smart Matching</h3>
            <p>
                Our hybrid matching system aligns volunteer skills, interests,
                and availability with the most suitable opportunities.
            </p>
        </div>

    </div>
</section>

<section class="vc-parallax" id="how-it-works">
    <div class="vc-parallax-overlay"></div>
    <div class="vc-parallax-content">
        <h2>How Volunteer Connect Works</h2>
        <p>A structured, transparent, and rewarding volunteering ecosystem.</p>
    </div>
</section>

<section class="vc-section vc-timeline">
    <div class="vc-container">

        <div class="vc-timeline-item">
            <div class="vc-timeline-text">
                <span class="vc-timeline-number">1</span>
                <h3>Create an Account</h3>
                <p>
                    Register as a Volunteer or Organization.
                    Complete your profile with skills and interests to unlock personalized features.
                </p>
            </div>
            <div class="vc-timeline-image">
                <img src="/volcon/assets/res/home/timeline-signup.jpg" alt="Illustration of account creation/sign-up page">
            </div>
        </div>

        <div class="vc-timeline-item">
            <div class="vc-timeline-text">
                <span class="vc-timeline-number">2</span>
                <h3>Discover & Match</h3>
                <p>
                    Volunteers discover opportunities using smart search and filters.
                    Our algorithm suggests the best roles, ensuring Organizations receive better-matched applicants.
                </p>
            </div>
            <div class="vc-timeline-image">
                <img src="/volcon/assets/res/home/timeline-discover.jpg" alt="Illustration of a search interface for volunteer opportunities">
            </div>
        </div>

        <div class="vc-timeline-item">
            <div class="vc-timeline-text">
                <span class="vc-timeline-number">3</span>
                <h3>Apply & Engage</h3>
                <p>
                    Apply directly with one click, track your application status,
                    and communicate securely with the organization once you are accepted.
                </p>
            </div>
            <div class="vc-timeline-image">
                <img src="/volcon/assets/res/home/timeline-engage.jpg" alt="Illustration of a volunteer working on a task">
            </div>
        </div>

        <div class="vc-timeline-item">
            <div class="vc-timeline-text">
                <span class="vc-timeline-number">4</span>
                <h3>Review & Improve</h3>
                <p>
                    After completion, both volunteers and organizations leave transparent reviews,
                    building trust and accountability across the entire platform ecosystem.
                </p>
            </div>
            <div class="vc-timeline-image">
                <img src="/volcon/assets/res/home/timeline-review.jpg" alt="Illustration of two people shaking hands with a rating interface">
            </div>
        </div>

    </div>
</section>

<section class="vc-section vc-roles">
    <div class="vc-container vc-grid-3">

        <div class="vc-role-card">
            <h3>For Volunteers</h3>
            <ul>
                <li>Skill-based opportunity matching</li>
                <li>Application tracking & notifications</li>
                <li>Volunteer history & reviews</li>
                <li>Personal impact portfolio</li>
            </ul>
        </div>

        <div class="vc-role-card">
            <h3>For Organizations</h3>
            <ul>
                <li>Opportunity creation & management</li>
                <li>Applicant shortlisting</li>
                <li>Volunteer communication</li>
                <li>Ratings & trust building</li>
            </ul>
        </div>

        <div class="vc-role-card">
            <h3>For Administrators</h3>
            <ul>
                <li>Platform oversight</li>
                <li>Approval workflows</li>
                <li>User & content moderation</li>
                <li>System analytics</li>
            </ul>
        </div>

    </div>
</section>

<section class="vc-cta">
    <div class="vc-cta-content">
        <h2>Start Making an Impact Today</h2>
        <p>
            Whether you want to volunteer, organize, or manage —
            Volunteer Connect empowers meaningful action.
        </p>
        <a href="/volcon/app/signup.php" class="vc-btn vc-btn-primary vc-btn-lg">
            Join Volunteer Connect
        </a>
    </div>
</section>

<script src="/volcon/assets/js/home.js"></script>

<?php require_once __DIR__ . "/app/views/layout/footer.php"; ?>