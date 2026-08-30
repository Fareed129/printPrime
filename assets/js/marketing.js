/**
 * PrimePrint Marketing Interactive Scripts
 */

document.addEventListener('DOMContentLoaded', () => {
  // 1. Sticky Header Background Transition on Scroll
  const header = document.querySelector('.mkt-header');
  window.addEventListener('scroll', () => {
    if (window.scrollY > 30) {
      header?.classList.add('scrolled');
    } else {
      header?.classList.remove('scrolled');
    }
  });

  // 2. Mobile Navigation Toggle
  const mobileToggle = document.getElementById('mobileNavToggle');
  const mobileNav = document.getElementById('mobileNavMenu');
  if (mobileToggle && mobileNav) {
    mobileToggle.addEventListener('click', () => {
      mobileNav.classList.toggle('open');
      const icon = mobileToggle.querySelector('i');
      if (icon) {
        icon.classList.toggle('bi-list');
        icon.classList.toggle('bi-x-lg');
      }
    });
  }

  // 3. Live Ticket Status Simulator on Hero
  const ticketStatusEl = document.getElementById('heroTicketStatus');
  const ticketJobIdEl = document.getElementById('heroTicketJobId');
  const ticketStatusIcon = document.getElementById('heroTicketIcon');

  if (ticketStatusEl) {
    const states = [
      { text: '01. UPLOAD RECEIVED', icon: 'bi-cloud-arrow-up-fill', color: '#1D4ED8', bg: '#EFF6FF', job: '#PRN-8491' },
      { text: '02. PAYMENT SETTLED', icon: 'bi-check-circle-fill', color: '#059669', bg: '#ECFDF5', job: '#PRN-8491' },
      { text: '03. SPOOLING TO AGENT', icon: 'bi-hdd-network-fill', color: '#D97706', bg: '#FFFBEB', job: '#PRN-8491' },
      { text: '04. PRINTING AT DESK', icon: 'bi-printer-fill', color: '#059669', bg: '#ECFDF5', job: '#PRN-8491' }
    ];
    let currentIndex = 0;

    setInterval(() => {
      currentIndex = (currentIndex + 1) % states.length;
      const cur = states[currentIndex];
      ticketStatusEl.style.opacity = '0';
      setTimeout(() => {
        ticketStatusEl.innerText = cur.text;
        ticketStatusEl.style.color = cur.color;
        ticketStatusEl.style.backgroundColor = cur.bg;
        if (ticketStatusIcon) ticketStatusIcon.className = `bi ${cur.icon} me-1`;
        if (ticketJobIdEl) ticketJobIdEl.innerText = cur.job;
        ticketStatusEl.style.opacity = '1';
      }, 200);
    }, 2800);
  }

  // 4. FAQ Accordion Interaction
  const faqItems = document.querySelectorAll('.faq-item');
  faqItems.forEach(item => {
    const questionBtn = item.querySelector('.faq-question');
    if (questionBtn) {
      questionBtn.addEventListener('click', () => {
        const isActive = item.classList.contains('active');
        // Close all
        faqItems.forEach(i => i.classList.remove('active'));
        // If not already active, open it
        if (!isActive) {
          item.classList.add('active');
        }
      });
    }
  });

  // 5. Login Portal Choice Modal
  window.openLoginModal = function() {
    const modal = document.getElementById('loginChoiceModal');
    if (modal) modal.classList.add('open');
  };

  window.closeLoginModal = function() {
    const modal = document.getElementById('loginChoiceModal');
    if (modal) modal.classList.remove('open');
  };

  // 6. Contact / Get Started Modal
  window.openContactModal = function() {
    const modal = document.getElementById('contactModal');
    if (modal) modal.classList.add('open');
  };

  window.closeContactModal = function() {
    const modal = document.getElementById('contactModal');
    if (modal) modal.classList.remove('open');
  };

  // Close modals on clicking backdrop
  document.querySelectorAll('.mkt-modal-backdrop').forEach(backdrop => {
    backdrop.addEventListener('click', (e) => {
      if (e.target === backdrop) {
        backdrop.classList.remove('open');
      }
    });
  });

  // 7. Demo Contact Form Submission
  const contactForm = document.getElementById('mktContactForm');
  if (contactForm) {
    contactForm.addEventListener('submit', (e) => {
      e.preventDefault();
      const btn = contactForm.querySelector('button[type="submit"]');
      if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<i class="bi bi-check2-circle me-1"></i> Request Received! We will contact you soon.';
        setTimeout(() => {
          window.closeContactModal();
          btn.disabled = false;
          btn.innerHTML = 'Submit Request';
          contactForm.reset();
        }, 2200);
      }
    });
  }
});
