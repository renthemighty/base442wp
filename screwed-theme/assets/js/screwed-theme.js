/**
 * Screwed TOS Scan — Theme JavaScript
 * "How Screwed Am I?" — App Landing Page Theme
 *
 * Vanilla JS, no dependencies, no jQuery.
 */
(function () {
  'use strict';

  /* =========================================================================
     Utility helpers
     ========================================================================= */

  /**
   * Shorthand querySelectorAll returning a real Array.
   * @param {string} selector
   * @param {Element|Document} [context=document]
   * @returns {Element[]}
   */
  function qsa(selector, context) {
    return Array.from((context || document).querySelectorAll(selector));
  }

  /**
   * Shorthand querySelector.
   * @param {string} selector
   * @param {Element|Document} [context=document]
   * @returns {Element|null}
   */
  function qs(selector, context) {
    return (context || document).querySelector(selector);
  }

  /**
   * Add one or more class names to an element (safe, no-op if missing).
   * @param {Element|null} el
   * @param {...string} classes
   */
  function addClass(el) {
    var classes = Array.prototype.slice.call(arguments, 1);
    if (el) classes.forEach(function (c) { el.classList.add(c); });
  }

  /**
   * Remove one or more class names from an element (safe, no-op if missing).
   * @param {Element|null} el
   * @param {...string} classes
   */
  function removeClass(el) {
    var classes = Array.prototype.slice.call(arguments, 1);
    if (el) classes.forEach(function (c) { el.classList.remove(c); });
  }

  /* =========================================================================
     1. Sticky Header on Scroll
     ========================================================================= */

  function initStickyHeader() {
    var header = qs('.screwed-header');
    if (!header) return;

    var SCROLL_THRESHOLD = 20; // px before triggering

    function onScroll() {
      if (window.scrollY > SCROLL_THRESHOLD) {
        addClass(header, 'screwed-header--scrolled');
      } else {
        removeClass(header, 'screwed-header--scrolled');
      }
    }

    // Run once immediately in case page loads mid-scroll
    onScroll();

    window.addEventListener('scroll', onScroll, { passive: true });
  }

  /* =========================================================================
     2. Mobile Menu Toggle
     ========================================================================= */

  function initMobileMenu() {
    var toggle   = qs('.screwed-menu-toggle');
    var mobileNav = qs('#screwed-mobile-nav');
    var backdrop  = qs('.screwed-mobile-nav__backdrop');

    if (!toggle || !mobileNav) return;

    var isOpen = false;

    function openMenu() {
      isOpen = true;
      addClass(mobileNav, 'is-open');
      if (backdrop) addClass(backdrop, 'is-visible');
      toggle.setAttribute('aria-expanded', 'true');
      mobileNav.setAttribute('aria-hidden', 'false');
      // Prevent body scroll while mobile nav is open
      document.body.style.overflow = 'hidden';
    }

    function closeMenu() {
      isOpen = false;
      removeClass(mobileNav, 'is-open');
      if (backdrop) removeClass(backdrop, 'is-visible');
      toggle.setAttribute('aria-expanded', 'false');
      mobileNav.setAttribute('aria-hidden', 'true');
      document.body.style.overflow = '';
    }

    function toggleMenu() {
      if (isOpen) {
        closeMenu();
      } else {
        openMenu();
      }
    }

    // Toggle button click
    toggle.addEventListener('click', function (e) {
      e.stopPropagation();
      toggleMenu();
    });

    // Close when any nav link inside mobile nav is clicked
    qsa('.screwed-mobile-nav__link', mobileNav).forEach(function (link) {
      link.addEventListener('click', function () {
        closeMenu();
      });
    });

    // Close on backdrop click
    if (backdrop) {
      backdrop.addEventListener('click', function () {
        closeMenu();
      });
    }

    // Close on outside click (anywhere on the document not inside the nav/toggle)
    document.addEventListener('click', function (e) {
      if (!isOpen) return;
      if (!mobileNav.contains(e.target) && !toggle.contains(e.target)) {
        closeMenu();
      }
    });

    // Close on Escape key
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && isOpen) {
        closeMenu();
        toggle.focus(); // return focus to trigger
      }
    });

    // Close mobile nav if viewport resizes past mobile breakpoint
    var mq = window.matchMedia('(min-width: 640px)');
    function onBreakpointChange(evt) {
      if (evt.matches && isOpen) {
        closeMenu();
      }
    }
    if (mq.addEventListener) {
      mq.addEventListener('change', onBreakpointChange);
    } else {
      // Fallback for Safari < 14
      mq.addListener(onBreakpointChange);
    }
  }

  /* =========================================================================
     3. Smooth Scroll for Anchor Links
     ========================================================================= */

  function initSmoothScroll() {
    // Respect prefers-reduced-motion
    var prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    document.addEventListener('click', function (e) {
      // Walk up the DOM to find an anchor tag
      var target = e.target;
      while (target && target !== document) {
        if (target.tagName === 'A') break;
        target = target.parentNode;
      }

      if (!target || target.tagName !== 'A') return;

      var href = target.getAttribute('href');
      if (!href) return;

      // Match same-page hash links: "#section" or "/#section" or "./page#section"
      var hashIndex = href.indexOf('#');
      if (hashIndex === -1) return;

      var hash   = href.slice(hashIndex); // e.g. "#how-it-works"
      var before = href.slice(0, hashIndex); // everything before "#"

      // Only handle if the path refers to the current page (or is empty/slash)
      var currentPath = window.location.pathname;
      if (before && before !== '/' && before !== currentPath && before !== '.') {
        return;
      }

      var destination = qs(hash);
      if (!destination) return;

      e.preventDefault();

      if (prefersReducedMotion) {
        destination.scrollIntoView();
      } else {
        destination.scrollIntoView({ behavior: 'smooth', block: 'start' });
      }

      // Update URL hash without jumping
      if (history.pushState) {
        history.pushState(null, '', hash);
      }
    });
  }

  /* =========================================================================
     4. IntersectionObserver — Scroll Reveal Animations
     ========================================================================= */

  function initScrollAnimations() {
    // If the browser doesn't support IntersectionObserver, just show everything
    if (!('IntersectionObserver' in window)) {
      qsa('.screwed-animate').forEach(function (el) {
        addClass(el, 'screwed-animate--visible');
      });
      return;
    }

    // Respect prefers-reduced-motion — skip animation, show elements immediately
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
      qsa('.screwed-animate').forEach(function (el) {
        addClass(el, 'screwed-animate--visible');
      });
      return;
    }

    var observerOptions = {
      root:       null,        // viewport
      rootMargin: '0px 0px -60px 0px', // trigger slightly before bottom edge
      threshold:  0.1          // 10% of the element visible
    };

    var observer = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) {
          addClass(entry.target, 'screwed-animate--visible');
          // Once revealed, stop observing to save resources
          observer.unobserve(entry.target);
        }
      });
    }, observerOptions);

    qsa('.screwed-animate').forEach(function (el) {
      observer.observe(el);
    });
  }

  /* =========================================================================
     5. Active Nav Link Highlighting (scroll-spy)
     ========================================================================= */

  function initScrollSpy() {
    var navLinks       = qsa('.screwed-nav__link[href^="#"], .screwed-nav__link[href^="/#"]');
    var mobileNavLinks = qsa('.screwed-mobile-nav__link[href^="#"], .screwed-mobile-nav__link[href^="/#"]');
    var allLinks       = navLinks.concat(mobileNavLinks);

    if (allLinks.length === 0) return;

    // Build a map: sectionId → [link, link, ...]
    var sectionMap = {}; // { sectionId: [linkEls] }

    allLinks.forEach(function (link) {
      var href      = link.getAttribute('href') || '';
      var hashIndex = href.indexOf('#');
      if (hashIndex === -1) return;
      var id = href.slice(hashIndex + 1);
      if (!id) return;
      if (!sectionMap[id]) sectionMap[id] = [];
      sectionMap[id].push(link);
    });

    // Gather section elements
    var sections = [];
    Object.keys(sectionMap).forEach(function (id) {
      var el = document.getElementById(id);
      if (el) sections.push(el);
    });

    if (sections.length === 0) return;

    var HEADER_OFFSET = 100; // px: account for fixed header height

    function getActiveSection() {
      var scrollY    = window.scrollY + HEADER_OFFSET;
      var docHeight  = document.documentElement.scrollHeight;
      var winHeight  = window.innerHeight;

      // If scrolled to very bottom, activate last section
      if (scrollY + winHeight >= docHeight - 10) {
        return sections[sections.length - 1];
      }

      // Walk sections in reverse to find the one we've scrolled past
      for (var i = sections.length - 1; i >= 0; i--) {
        if (sections[i].offsetTop <= scrollY) {
          return sections[i];
        }
      }

      return null;
    }

    function updateActiveLinks() {
      var activeSection = getActiveSection();

      // Remove active class from all links
      allLinks.forEach(function (link) {
        removeClass(link, 'screwed-nav__link--active');
      });

      if (!activeSection) return;

      var id = activeSection.getAttribute('id');
      if (!id || !sectionMap[id]) return;

      sectionMap[id].forEach(function (link) {
        addClass(link, 'screwed-nav__link--active');
      });
    }

    // Run on scroll
    window.addEventListener('scroll', updateActiveLinks, { passive: true });

    // Run once on load
    updateActiveLinks();
  }

  /* =========================================================================
     6. Hero section — parallax-lite on orbs (optional, non-essential)
     ========================================================================= */

  function initOrbParallax() {
    var orbs = qsa('.screwed-hero__orb');
    if (orbs.length === 0) return;

    // Bail on reduced motion or on touch devices
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
    if ('ontouchstart' in window) return;

    var hero = qs('.screwed-hero');
    if (!hero) return;

    var ticking  = false;
    var mouseX   = 0;
    var mouseY   = 0;
    var strength = [0.02, -0.015, 0.01]; // per-orb movement multiplier

    function updateOrbs() {
      var rect      = hero.getBoundingClientRect();
      var centerX   = rect.left + rect.width  / 2;
      var centerY   = rect.top  + rect.height / 2;
      var relX      = mouseX - centerX;
      var relY      = mouseY - centerY;

      orbs.forEach(function (orb, idx) {
        var factor = strength[idx] || 0.01;
        var dx     = relX * factor;
        var dy     = relY * factor;
        orb.style.transform = 'translate(' + dx + 'px, ' + dy + 'px)';
      });

      ticking = false;
    }

    hero.addEventListener('mousemove', function (e) {
      mouseX = e.clientX;
      mouseY = e.clientY;

      if (!ticking) {
        requestAnimationFrame(updateOrbs);
        ticking = true;
      }
    });

    hero.addEventListener('mouseleave', function () {
      // Reset orbs gently
      orbs.forEach(function (orb) {
        orb.style.transform = '';
      });
    });
  }

  /* =========================================================================
     7. External link safety (open in new tab, add rel attributes)
     ========================================================================= */

  function initExternalLinks() {
    var currentHost = window.location.hostname;

    qsa('a[href]').forEach(function (link) {
      var href = link.getAttribute('href');
      if (!href) return;

      try {
        // Skip relative, hash, mailto, tel links
        if (
          href.startsWith('#')   ||
          href.startsWith('/')   ||
          href.startsWith('.')   ||
          href.startsWith('mailto:') ||
          href.startsWith('tel:')
        ) return;

        var url = new URL(href);
        if (url.hostname && url.hostname !== currentHost) {
          link.setAttribute('target', '_blank');
          link.setAttribute('rel', 'noopener noreferrer');
        }
      } catch (err) {
        // Relative URL or invalid — skip
      }
    });
  }

  /* =========================================================================
     8. Initialise everything on DOMContentLoaded
     ========================================================================= */

  function init() {
    initStickyHeader();
    initMobileMenu();
    initSmoothScroll();
    initScrollAnimations();
    initScrollSpy();
    initOrbParallax();
    initExternalLinks();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    // DOM already parsed (script loaded with defer or at bottom of body)
    init();
  }

})();
