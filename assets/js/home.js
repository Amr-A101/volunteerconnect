/**
 * Volunteer Connect Homepage - Interactive JavaScript
 * Handles hero slideshow, scroll animations, and interactive effects
 */

document.addEventListener('DOMContentLoaded', function() {
    
    // =========================
    // HERO SLIDESHOW
    // =========================
    const slides = document.querySelectorAll('.vc-hero-slide');
    let currentSlide = 0;
    const slideInterval = 5000; // 5 seconds

    function showSlide(index) {
        slides.forEach((slide, i) => {
            slide.classList.remove('active');
            if (i === index) {
                slide.classList.add('active');
            }
        });
    }

    function nextSlide() {
        currentSlide = (currentSlide + 1) % slides.length;
        showSlide(currentSlide);
    }

    // Auto-advance slideshow
    let slideshowTimer = setInterval(nextSlide, slideInterval);

    // Pause on hover
    const heroSection = document.querySelector('.vc-hero');
    if (heroSection) {
        heroSection.addEventListener('mouseenter', () => {
            clearInterval(slideshowTimer);
        });

        heroSection.addEventListener('mouseleave', () => {
            slideshowTimer = setInterval(nextSlide, slideInterval);
        });
    }

    // =========================
    // SMOOTH SCROLL FOR ANCHOR LINKS
    // =========================
    const anchorLinks = document.querySelectorAll('a[href^="#"]');
    
    anchorLinks.forEach(link => {
        link.addEventListener('click', function(e) {
            const href = this.getAttribute('href');
            
            // Skip empty anchors
            if (href === '#' || href === '#!') return;
            
            const target = document.querySelector(href);
            
            if (target) {
                e.preventDefault();
                const headerOffset = 80;
                const elementPosition = target.getBoundingClientRect().top;
                const offsetPosition = elementPosition + window.pageYOffset - headerOffset;

                window.scrollTo({
                    top: offsetPosition,
                    behavior: 'smooth'
                });
            }
        });
    });

    // =========================
    // SCROLL ANIMATIONS
    // =========================
    const observerOptions = {
        threshold: 0.15,
        rootMargin: '0px 0px -50px 0px'
    };

    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.classList.add('animate-in');
                
                // Animate children with stagger effect
                const children = entry.target.querySelectorAll('.vc-feature-card, .vc-role-card');
                children.forEach((child, index) => {
                    setTimeout(() => {
                        child.style.opacity = '0';
                        child.style.transform = 'translateY(30px)';
                        child.style.transition = 'all 0.6s ease';
                        
                        setTimeout(() => {
                            child.style.opacity = '1';
                            child.style.transform = 'translateY(0)';
                        }, 50);
                    }, index * 150);
                });
            }
        });
    }, observerOptions);

    // Observe sections
    const animatedSections = document.querySelectorAll('.vc-features, .vc-roles, .vc-intro');
    animatedSections.forEach(section => observer.observe(section));

    // =========================
    // FEATURE CARDS - TILT EFFECT
    // =========================
    const featureCards = document.querySelectorAll('.vc-feature-card, .vc-role-card');
    
    featureCards.forEach(card => {
        card.addEventListener('mousemove', (e) => {
            const rect = card.getBoundingClientRect();
            const x = e.clientX - rect.left;
            const y = e.clientY - rect.top;
            
            const centerX = rect.width / 2;
            const centerY = rect.height / 2;
            
            const rotateX = (y - centerY) / 20;
            const rotateY = (centerX - x) / 20;
            
            card.style.transform = `perspective(1000px) rotateX(${rotateX}deg) rotateY(${rotateY}deg) translateY(-10px)`;
        });
        
        card.addEventListener('mouseleave', () => {
            card.style.transform = 'perspective(1000px) rotateX(0) rotateY(0) translateY(0)';
        });
    });

    // =========================
    // PARALLAX EFFECT
    // =========================
    const parallaxSection = document.querySelector('.vc-parallax');
    
    if (parallaxSection) {
        window.addEventListener('scroll', () => {
            const scrolled = window.pageYOffset;
            const parallaxOffset = parallaxSection.offsetTop;
            const parallaxHeight = parallaxSection.offsetHeight;
            
            if (scrolled > parallaxOffset - window.innerHeight && scrolled < parallaxOffset + parallaxHeight) {
                const yPos = (scrolled - parallaxOffset) * 0.5;
                parallaxSection.style.backgroundPositionY = `${yPos}px`;
            }
        });
    }

    // =========================
    // COUNTER ANIMATION (if you add stats)
    // =========================
    function animateCounter(element, target, duration = 2000) {
        const start = 0;
        const increment = target / (duration / 16);
        let current = start;
        
        const timer = setInterval(() => {
            current += increment;
            if (current >= target) {
                current = target;
                clearInterval(timer);
            }
            element.textContent = Math.floor(current).toLocaleString();
        }, 16);
    }

    // Activate counters when visible
    const counters = document.querySelectorAll('[data-counter]');
    const counterObserver = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting && !entry.target.classList.contains('counted')) {
                const target = parseInt(entry.target.getAttribute('data-counter'));
                animateCounter(entry.target, target);
                entry.target.classList.add('counted');
            }
        });
    }, { threshold: 0.5 });

    counters.forEach(counter => counterObserver.observe(counter));

    // =========================
    // HERO CONTENT ANIMATION
    // =========================
    const heroContent = document.querySelector('.vc-hero-content');
    if (heroContent) {
        setTimeout(() => {
            heroContent.style.opacity = '1';
            heroContent.style.transform = 'translateY(0)';
        }, 200);
    }

    // =========================
    // BUTTON RIPPLE EFFECT
    // =========================
    const buttons = document.querySelectorAll('.vc-btn');
    
    buttons.forEach(button => {
        button.addEventListener('click', function(e) {
            const ripple = document.createElement('span');
            ripple.classList.add('ripple');
            
            const rect = this.getBoundingClientRect();
            const size = Math.max(rect.width, rect.height);
            const x = e.clientX - rect.left - size / 2;
            const y = e.clientY - rect.top - size / 2;
            
            ripple.style.width = ripple.style.height = size + 'px';
            ripple.style.left = x + 'px';
            ripple.style.top = y + 'px';
            ripple.style.position = 'absolute';
            ripple.style.borderRadius = '50%';
            ripple.style.background = 'rgba(255, 255, 255, 0.5)';
            ripple.style.transform = 'scale(0)';
            ripple.style.animation = 'ripple 0.6s ease-out';
            ripple.style.pointerEvents = 'none';
            
            this.appendChild(ripple);
            
            setTimeout(() => ripple.remove(), 600);
        });
    });

    // =========================
    // TIMELINE PROGRESS ON SCROLL
    // =========================
    const timelineItems = document.querySelectorAll('.vc-timeline-item');
    
    const timelineObserver = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.style.opacity = '1';
                entry.target.style.transform = 'translateX(0)';
            }
        });
    }, { threshold: 0.3 });

    timelineItems.forEach(item => timelineObserver.observe(item));

    // =========================
    // NAVBAR BACKGROUND ON SCROLL (if header exists)
    // =========================
    const header = document.querySelector('header');
    if (header) {
        window.addEventListener('scroll', () => {
            if (window.scrollY > 50) {
                header.classList.add('scrolled');
            } else {
                header.classList.remove('scrolled');
            }
        });
    }

    // =========================
    // ADD RIPPLE ANIMATION STYLES
    // =========================
    const style = document.createElement('style');
    style.textContent = `
        @keyframes ripple {
            to {
                transform: scale(4);
                opacity: 0;
            }
        }
        
        .vc-btn {
            position: relative;
            overflow: hidden;
        }
        
        .animate-in {
            animation: fadeIn 0.8s ease-out;
        }
        
        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
    `;
    document.head.appendChild(style);

    // =========================
    // CONSOLE GREETING
    // =========================
    console.log('%c👋 Welcome to Volunteer Connect!', 'font-size: 20px; color: #2563eb; font-weight: bold;');
    console.log('%cBuilt with ❤️ for meaningful impact', 'font-size: 14px; color: #64748b;');
});

// =========================
// UTILITY: DEBOUNCE FUNCTION
// =========================
function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            clearTimeout(timeout);
            func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
}

// =========================
// PERFORMANCE: OPTIMIZE SCROLL LISTENERS
// =========================
const optimizedScroll = debounce(() => {
    // Any additional scroll-based functionality
}, 100);

window.addEventListener('scroll', optimizedScroll);