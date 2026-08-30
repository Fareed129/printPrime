<?php
/**
 * PrimePrint Public Marketing Website Footer
 */
?>
  <!-- Footer -->
  <footer class="mkt-footer">
    <div class="footer-container">
      <div class="footer-grid">
        
        <!-- Brand Summary -->
        <div>
          <div class="d-flex align-items-center gap-2 mb-3">
            <div class="mkt-brand-icon" style="width: 32px; height: 32px; font-size: 0.95rem;">
              <i class="bi bi-printer-fill"></i>
            </div>
            <span class="footer-brand-title mb-0">PrimePrint</span>
          </div>
          <p style="font-size: 0.88rem; line-height: 1.6; color: #8F95A3; margin-bottom: 20px;">
            The multi-tenant cloud printing operating system for modern printing & document service counters across India.
          </p>
          <div class="d-flex gap-2">
            <span class="paper-tag" style="background: #1C2026; border-color: #2D333D; color: #9EADB8;">
              <i class="bi bi-shield-lock-fill text-success me-1"></i> 256-bit Encrypted
            </span>
            <span class="paper-tag" style="background: #1C2026; border-color: #2D333D; color: #9EADB8;">
              <i class="bi bi-lightning-charge-fill text-warning me-1"></i> UPI Direct
            </span>
          </div>
        </div>

        <!-- Product Links -->
        <div>
          <h4 class="footer-heading">Product</h4>
          <ul class="footer-links">
            <li><a href="#how-it-works">How It Works</a></li>
            <li><a href="#product">Live UI Showcase</a></li>
            <li><a href="#architecture">Print Agent Spooler</a></li>
            <li><a href="#for-shops">For Print Shops</a></li>
            <li><a href="#faq">Frequently Asked Questions</a></li>
          </ul>
        </div>

        <!-- Portals & Access -->
        <div>
          <h4 class="footer-heading">Access Portals</h4>
          <ul class="footer-links">
            <li><a href="<?= APP_URL ?>/shop/login.php"><i class="bi bi-shop me-1 text-primary"></i> Shop Manager Login</a></li>
            <li><a href="<?= APP_URL ?>/login.php"><i class="bi bi-shield-lock me-1 text-secondary"></i> Super Admin Console</a></li>
            <li><a href="<?= APP_URL ?>/p/abc-digital-printing"><i class="bi bi-qr-code me-1 text-success"></i> Sample Customer Portal</a></li>
            <li><a href="#for-shops">Hardware Setup Guide</a></li>
          </ul>
        </div>

        <!-- Compliance & Security -->
        <div>
          <h4 class="footer-heading">Trust & Security</h4>
          <ul class="footer-links">
            <li><span class="text-white-50">Razorpay Payment Gateway</span></li>
            <li><span class="text-white-50">Windows Print Spooler Bridge</span></li>
            <li><span class="text-white-50">Server-Side Page Verification</span></li>
            <li><span class="text-white-50">Zero Customer App Download</span></li>
          </ul>
        </div>

      </div>

      <!-- Footer Bottom -->
      <div class="footer-bottom">
        <div>
          &copy; <?= date('Y') ?> <strong>PrimePrint SaaS</strong>. All rights reserved. Built for physical printing counters.
        </div>
        <div style="font-family: var(--font-mono); color: #6E7582;">
          VERSION 2.4 · CLOUD ENGINE ACTIVE
        </div>
      </div>
    </div>
  </footer>

  <!-- ========================================================= -->
  <!-- MODAL: LOGIN PORTAL SELECTOR -->
  <!-- ========================================================= -->
  <div class="mkt-modal-backdrop" id="loginChoiceModal">
    <div class="mkt-modal-dialog">
      <button type="button" class="modal-close-btn" onclick="closeLoginModal()">&times;</button>
      <div class="text-center mb-4">
        <div class="mkt-brand-icon mx-auto mb-2" style="width: 44px; height: 44px; font-size: 1.25rem;">
          <i class="bi bi-box-arrow-in-right"></i>
        </div>
        <h3 class="fw-bold text-dark mb-1 font-display">Sign In to PrimePrint</h3>
        <p class="small text-muted mb-0">Select your account portal to continue</p>
      </div>

      <!-- Shop Manager Portal Option -->
      <a href="<?= APP_URL ?>/shop/login.php" class="portal-choice-card">
        <div class="mkt-brand-icon" style="background: linear-gradient(135deg, #059669, #10B981); width: 44px; height: 44px; flex-shrink: 0;">
          <i class="bi bi-shop"></i>
        </div>
        <div>
          <div class="fw-bold text-dark">Shop Manager Portal</div>
          <div class="small text-muted">Manage your printing shop, queue, prices & QR standee</div>
        </div>
        <i class="bi bi-chevron-right ms-auto text-muted"></i>
      </a>

      <!-- Super Admin Console Option -->
      <a href="<?= APP_URL ?>/login.php" class="portal-choice-card">
        <div class="mkt-brand-icon" style="background: linear-gradient(135deg, #1D4ED8, #3B82F6); width: 44px; height: 44px; flex-shrink: 0;">
          <i class="bi bi-shield-lock-fill"></i>
        </div>
        <div>
          <div class="fw-bold text-dark">Super Admin Console</div>
          <div class="small text-muted">Platform management, multi-shop licensing & reports</div>
        </div>
        <i class="bi bi-chevron-right ms-auto text-muted"></i>
      </a>

      <div class="text-center mt-3 pt-2 border-top">
        <span class="small text-muted">Are you a customer wanting to print? Scan the QR code at your local print shop counter.</span>
      </div>
    </div>
  </div>

  <!-- ========================================================= -->
  <!-- MODAL: GET STARTED / CONTACT INQUIRY -->
  <!-- ========================================================= -->
  <div class="mkt-modal-backdrop" id="contactModal">
    <div class="mkt-modal-dialog">
      <button type="button" class="modal-close-btn" onclick="closeContactModal()">&times;</button>
      <div class="mb-3">
        <h3 class="fw-bold text-dark mb-1 font-display">Get PrimePrint for Your Shop</h3>
        <p class="small text-muted mb-0">Upgrade your printing counter with QR automation & instant payments.</p>
      </div>

      <form id="mktContactForm">
        <div style="margin-bottom: 14px;">
          <label style="display: block; font-size: 0.82rem; font-weight: 700; margin-bottom: 4px; color: var(--pp-ink);">Shop / Business Name</label>
          <input type="text" class="form-control" style="width: 100%; padding: 10px 14px; border: 1px solid var(--pp-border); border-radius: 8px; font-family: var(--font-body);" placeholder="e.g. Metro Xerox & Digital Prints" required>
        </div>
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 14px;">
          <div>
            <label style="display: block; font-size: 0.82rem; font-weight: 700; margin-bottom: 4px; color: var(--pp-ink);">Contact Name</label>
            <input type="text" class="form-control" style="width: 100%; padding: 10px 14px; border: 1px solid var(--pp-border); border-radius: 8px; font-family: var(--font-body);" placeholder="Your Name" required>
          </div>
          <div>
            <label style="display: block; font-size: 0.82rem; font-weight: 700; margin-bottom: 4px; color: var(--pp-ink);">Phone (WhatsApp)</label>
            <input type="tel" class="form-control" style="width: 100%; padding: 10px 14px; border: 1px solid var(--pp-border); border-radius: 8px; font-family: var(--font-body);" placeholder="+91 98765 43210" required>
          </div>
        </div>
        <div style="margin-bottom: 14px;">
          <label style="display: block; font-size: 0.82rem; font-weight: 700; margin-bottom: 4px; color: var(--pp-ink);">Printers You Operate</label>
          <input type="text" class="form-control" style="width: 100%; padding: 10px 14px; border: 1px solid var(--pp-border); border-radius: 8px; font-family: var(--font-body);" placeholder="e.g. Canon iR 3025, HP LaserJet Pro, Epson L3150" required>
        </div>
        <div style="margin-bottom: 18px;">
          <label style="display: block; font-size: 0.82rem; font-weight: 700; margin-bottom: 4px; color: var(--pp-ink);">City / Location</label>
          <input type="text" class="form-control" style="width: 100%; padding: 10px 14px; border: 1px solid var(--pp-border); border-radius: 8px; font-family: var(--font-body);" placeholder="e.g. Mumbai, Bengaluru, Delhi" required>
        </div>

        <button type="submit" class="btn-pp-blue" style="width: 100%; justify-content: center;">
          <span>Submit Request</span>
          <i class="bi bi-send-fill ms-1"></i>
        </button>
      </form>
    </div>
  </div>

  <!-- Structured Data (JSON-LD) for SEO -->
  <script type="application/ld+json">
  {
    "@context": "https://schema.org",
    "@type": "SoftwareApplication",
    "name": "PrimePrint",
    "operatingSystem": "Web, Windows",
    "applicationCategory": "BusinessApplication",
    "description": "Multi-tenant cloud automation and QR self-service printing operating system for physical printing shops in India.",
    "offers": {
      "@type": "Offer",
      "price": "1499.00",
      "priceCurrency": "INR"
    }
  }
  </script>

  <!-- Marketing Scripts -->
  <script src="<?= asset_url('assets/js/marketing.js') ?>"></script>
</body>
</html>
