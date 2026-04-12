/* ============================================================
   PANIC BOOKING — script.js
   ============================================================ */

(function () {
  'use strict';

  /* ── Mobile Nav ────────────────────────────────────────── */
  const hamburger = document.querySelector('.hamburger');
  const mobileNav = document.querySelector('.mobile-nav');
  const mobileLinks = mobileNav ? mobileNav.querySelectorAll('a') : [];

  function toggleMobileNav(open) {
    hamburger.classList.toggle('open', open);
    mobileNav.classList.toggle('open', open);
    hamburger.setAttribute('aria-expanded', String(open));
    mobileNav.setAttribute('aria-hidden', String(!open));
    document.body.style.overflow = open ? 'hidden' : '';
  }

  if (hamburger && mobileNav) {
    hamburger.addEventListener('click', () => {
      const isOpen = hamburger.classList.contains('open');
      toggleMobileNav(!isOpen);
    });

    mobileLinks.forEach(link => {
      link.addEventListener('click', () => toggleMobileNav(false));
    });

    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && hamburger.classList.contains('open')) {
        toggleMobileNav(false);
        hamburger.focus();
      }
    });
  }

  /* ── Scroll: header shadow ─────────────────────────────── */
  const header = document.querySelector('.site-header');
  if (header) {
    const onScroll = () => {
      header.style.borderBottomColor = window.scrollY > 20
        ? 'rgba(232,21,42,0.4)'
        : 'var(--clr-grey)';
    };
    window.addEventListener('scroll', onScroll, { passive: true });
  }

  /* ── Scroll Reveal ─────────────────────────────────────── */
  const revealTargets = document.querySelectorAll(
    '.service-card, .genre-pill, .lm-step, .value-item, .contact-method, .stat, .section-header'
  );

  if ('IntersectionObserver' in window && revealTargets.length) {
    revealTargets.forEach(el => el.classList.add('reveal'));

    const observer = new IntersectionObserver((entries) => {
      entries.forEach(entry => {
        if (entry.isIntersecting) {
          entry.target.classList.add('visible');
          observer.unobserve(entry.target);
        }
      });
    }, { threshold: 0.12, rootMargin: '0px 0px -40px 0px' });

    revealTargets.forEach((el, i) => {
      el.style.transitionDelay = `${(i % 6) * 60}ms`;
      observer.observe(el);
    });
  }

  /* ── Smooth scroll: active nav link ───────────────────── */
  const sections = document.querySelectorAll('section[id]');
  const navLinks = document.querySelectorAll('.main-nav a[href^="#"]');

  if (sections.length && navLinks.length) {
    const sectionObserver = new IntersectionObserver((entries) => {
      entries.forEach(entry => {
        if (entry.isIntersecting) {
          const id = entry.target.getAttribute('id');
          navLinks.forEach(link => {
            link.classList.toggle('active', link.getAttribute('href') === `#${id}`);
          });
        }
      });
    }, { threshold: 0.35 });

    sections.forEach(s => sectionObserver.observe(s));
  }

  /* ── Contact form ──────────────────────────────────────── */
  const form = document.querySelector('.contact-form');
  if (form) {
    const submitBtn = form.querySelector('button[type="submit"]');

    form.addEventListener('submit', (e) => {
      e.preventDefault();

      // Basic client-side validation
      const required = form.querySelectorAll('[required]');
      let valid = true;
      required.forEach(field => {
        if (!field.value.trim()) {
          valid = false;
          field.style.borderColor = 'var(--clr-red)';
          field.addEventListener('input', () => {
            field.style.borderColor = '';
          }, { once: true });
        }
      });

      if (!valid) return;

      // Simulate submission feedback
      const originalText = submitBtn.textContent;
      submitBtn.textContent = 'Sending…';
      submitBtn.disabled = true;

      setTimeout(() => {
        submitBtn.textContent = '✓ Message Sent!';
        submitBtn.style.background = '#1a7a3a';
        submitBtn.style.borderColor = '#1a7a3a';
        form.reset();

        setTimeout(() => {
          submitBtn.textContent = originalText;
          submitBtn.disabled = false;
          submitBtn.style.background = '';
          submitBtn.style.borderColor = '';
        }, 3500);
      }, 900);
    });
  }

  /* ── Genre pill random accent on hover ─────────────────── */
  const accents = ['#e8152a', '#f5d800', '#ff6b35', '#00b4d8', '#a8edea'];
  document.querySelectorAll('.genre-pill').forEach(pill => {
    pill.addEventListener('mouseenter', () => {
      const color = accents[Math.floor(Math.random() * accents.length)];
      pill.style.borderColor = color;
      pill.style.color = color === '#f5d800' ? '#f5d800' : '#f5f0e8';
      pill.style.background = `${color}18`;
    });
    pill.addEventListener('mouseleave', () => {
      pill.style.borderColor = '';
      pill.style.color = '';
      pill.style.background = '';
    });
  });

})();
