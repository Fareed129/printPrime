<?php
/**
 * PrimePrint — Public Marketing Website & Homepage
 * Automated Cloud Printing Operating System for Physical Printing Counters
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/helpers.php';
require_once __DIR__ . '/config/auth.php';

$pageTitle = 'PrimePrint — Printing Shop Automation Software';
$metaDescription = 'PrimePrint turns physical document printing counters into automated self-service stations with QR uploads, instant UPI payments, and Windows Print Agent spooling.';

require_once __DIR__ . '/includes/marketing-header.php';
?>

<!-- ========================================================= -->
<!-- 1. HERO SECTION: Print Without the Counter -->
<!-- ========================================================= -->
<section class="hero-section bg-grid-paper" id="hero">
  <div class="hero-container">
    
    <!-- Hero Left Column: Narrative Copy -->
    <div>
      <div class="hero-pill">
        <span class="hero-pill-indicator"></span>
        <span>SaaS Operating System for Print Shops</span>
      </div>

      <h1 class="hero-title">
        Print without <br>
        the <span class="accent-ink">counter.</span>
      </h1>

      <p class="hero-subtitle">
        PrimePrint turns the everyday printing counter into a simple digital workflow. Customers upload their files, choose preferences, pay online, and the shop's printer takes it from there.
      </p>

      <div class="hero-cta-group">
        <button type="button" class="btn-pp-blue" onclick="openContactModal()">
          <i class="bi bi-shop me-1"></i> For Printing Shops
        </button>
        <a href="#how-it-works" class="btn-pp-outline">
          <i class="bi bi-play-circle me-1"></i> See How It Works
        </a>
      </div>

      <!-- Factual Platform Metrics -->
      <div class="hero-metrics">
        <div class="hero-metric-item">
          <span class="hero-metric-val">4 Steps</span>
          <span class="hero-metric-label">Scan → Print</span>
        </div>
        <div class="hero-metric-item">
          <span class="hero-metric-val">0 Apps</span>
          <span class="hero-metric-label">Zero App Installs</span>
        </div>
        <div class="hero-metric-item">
          <span class="hero-metric-val">100%</span>
          <span class="hero-metric-label">Prepaid Assurance</span>
        </div>
      </div>
    </div>

    <!-- Hero Right Column: Environmental Photo + Live Floating Ticket Card -->
    <div class="hero-visual-wrapper">
      <div class="crop-frame hero-photo-card">
        <img src="<?= asset_url('assets/images/marketing/hero_print_shop.jpg') ?>" alt="Indian Digital Printing Shop Counter" class="hero-photo-img">
      </div>

      <!-- Live HTML/CSS Interactive Ticket Simulator -->
      <div class="live-ticket-card">
        <div class="ticket-header">
          <div class="d-flex align-items-center gap-2">
            <i class="bi bi-printer-fill text-primary"></i>
            <strong class="font-display small text-dark">PRIMEPRINT TICKET</strong>
          </div>
          <span class="ticket-badge" id="heroTicketJobId">#PRN-8491</span>
        </div>

        <div class="ticket-file-row">
          <div class="ticket-file-icon">
            <i class="bi bi-file-earmark-pdf-fill"></i>
          </div>
          <div>
            <div class="ticket-file-name">Aadhaar_Card_Copy.pdf</div>
            <div class="ticket-file-meta">06 PAGES · A4 · COLOR · DUPLEX</div>
          </div>
        </div>

        <div class="ticket-price-row">
          <span class="ticket-price-label">Exact Calculated Rate:</span>
          <span class="ticket-price-val">₹18.00</span>
        </div>

        <div class="ticket-status-pill" id="heroTicketStatus" style="transition: all 0.2s ease;">
          <span><i class="bi bi-cloud-arrow-up-fill me-1" id="heroTicketIcon"></i> 01. UPLOAD RECEIVED</span>
          <span class="badge bg-white text-dark rounded-pill px-2 py-0 border" style="font-size: 0.65rem;">LIVE</span>
        </div>
      </div>
    </div>

  </div>
</section>

<!-- ========================================================= -->
<!-- 2. THE PROBLEM SECTION: The Counter Bottleneck -->
<!-- ========================================================= -->
<section class="section-problem" id="problem">
  <div class="container-xl" style="max-width: 1240px; margin: 0 auto;">
    
    <div class="section-header-center">
      <span class="section-tag">THE TRADITIONAL COUNTER STRUGGLE</span>
      <h2 class="section-title">Printing shouldn't feel like this.</h2>
      <p class="section-desc">
        Every day, millions of customers crowd local print counters. Shop owners juggle cables, WhatsApp chats, USB pen drives, and cash change calculations while long queues form on the footpath.
      </p>
    </div>

    <div class="problem-grid">
      
      <!-- Old Broken Workflow List -->
      <div class="problem-card-old">
        <div class="d-flex align-items-center justify-content-between mb-3">
          <span class="paper-tag" style="background: #FEE2E2; border-color: #FECACA; color: #991B1B;">
            <i class="bi bi-x-circle-fill me-1"></i> THE MANUAL MESS
          </span>
          <span class="small text-muted font-mono">7 WASTED STEPS</span>
        </div>

        <h4 class="fw-bold text-dark font-display mb-3">The Old Counter Bottleneck</h4>

        <div class="old-flow-steps">
          <div class="old-flow-item">
            <span class="font-mono text-muted">01</span>
            <span>Customer connects to shop Wi-Fi or cables</span>
          </div>
          <div class="old-flow-item">
            <span class="font-mono text-muted">02</span>
            <span>Sends documents over cluttered WhatsApp numbers</span>
          </div>
          <div class="old-flow-item warning">
            <span class="font-mono">03</span>
            <span>Operator manually finds & downloads files on computer</span>
          </div>
          <div class="old-flow-item">
            <span class="font-mono text-muted">04</span>
            <span>Questions: <em>"B&W or Color? Single or double sided?"</em></span>
          </div>
          <div class="old-flow-item">
            <span class="font-mono text-muted">05</span>
            <span>Manual calculator math for custom page counts</span>
          </div>
          <div class="old-flow-item warning">
            <span class="font-mono">06</span>
            <span>Customer prints first, then walks away or disputes price</span>
          </div>
          <div class="old-flow-item">
            <span class="font-mono text-muted">07</span>
            <span>Operator manually sends job to physical printer queue</span>
          </div>
        </div>

        <div class="p-3 bg-light rounded-3 border text-muted small">
          <i class="bi bi-arrow-right-circle text-danger me-1"></i> <strong>Result:</strong> 5–8 minutes wasted per customer. Counter congestion, unpaid print wastage, and virus risks from customer flash drives.
        </div>
      </div>

      <!-- Problem Photography -->
      <div class="crop-frame">
        <div class="paper-card p-2" style="overflow: hidden; border-radius: 18px;">
          <img src="<?= asset_url('assets/images/marketing/problem_counter_queue.jpg') ?>" alt="Busy Printing Counter in India" style="width: 100%; height: 420px; object-fit: cover; border-radius: 12px; display: block;">
        </div>
      </div>

    </div>

  </div>
</section>

<!-- ========================================================= -->
<!-- 3. THE 4-STEP CONNECTED WORKFLOW -->
<!-- ========================================================= -->
<section class="section-workflow" id="how-it-works">
  <div class="workflow-container">
    
    <div class="section-header-center">
      <span class="section-tag">STREAMLINED DIGITAL ARCHITECTURE</span>
      <h2 class="section-title">One scan. Four steps.</h2>
      <p class="section-desc">
        PrimePrint replaces manual counter file transfers with a direct browser upload, verified server pricing, instant UPI checkout, and automated spooling.
      </p>
    </div>

    <!-- 4 Step Cards -->
    <div class="workflow-steps-grid">
      
      <!-- Step 1 -->
      <div class="step-card">
        <div class="step-number">
          <span>01</span>
          <span class="paper-tag">ENTRY</span>
        </div>
        <div class="step-icon-wrap">
          <i class="bi bi-qr-code-scan"></i>
        </div>
        <h3 class="step-title">Scan Shop QR</h3>
        <p class="step-desc">
          Customer scans the physical counter standee using their smartphone camera or any UPI app. No app download or registration needed.
        </p>
      </div>

      <!-- Step 2 -->
      <div class="step-card">
        <div class="step-number">
          <span>02</span>
          <span class="paper-tag">CONFIG</span>
        </div>
        <div class="step-icon-wrap">
          <i class="bi bi-cloud-arrow-up-fill"></i>
        </div>
        <h3 class="step-title">Upload & Select</h3>
        <p class="step-desc">
          Customer uploads PDF or images. The server detects exact page count while the customer chooses B&W/Color, Single/Double sided, and Copies.
        </p>
      </div>

      <!-- Step 3 -->
      <div class="step-card">
        <div class="step-number">
          <span>03</span>
          <span class="paper-tag">PAY</span>
        </div>
        <div class="step-icon-wrap">
          <i class="bi bi-lightning-charge-fill"></i>
        </div>
        <h3 class="step-title">1-Click UPI Pay</h3>
        <p class="step-desc">
          Exact price is calculated server-side. Customer pays via Google Pay, PhonePe, Paytm, or Card with cryptographic Razorpay verification.
        </p>
      </div>

      <!-- Step 4 -->
      <div class="step-card">
        <div class="step-number">
          <span>04</span>
          <span class="paper-tag">PRINT</span>
        </div>
        <div class="step-icon-wrap">
          <i class="bi bi-printer-fill"></i>
        </div>
        <h3 class="step-title">Auto-Spool & Print</h3>
        <p class="step-desc">
          The Windows Print Agent on the shop PC instantly receives the paid print job and silently prints it on the shop's default desktop printer.
        </p>
      </div>

    </div>

  </div>
</section>

<!-- ========================================================= -->
<!-- 4. REAL PRODUCT UI SHOWCASE -->
<!-- ========================================================= -->
<section class="section-product-ui" id="product">
  <div class="container-xl" style="max-width: 1240px; margin: 0 auto;">
    
    <div class="section-header-center">
      <span class="section-tag">BUILT FOR REAL SHOP WORKFLOWS</span>
      <h2 class="section-title">Software that understands the counter.</h2>
      <p class="section-desc">
        Take a look inside the actual PrimePrint platform. Built with dedicated tools for shop operators to configure hardware, set rates, and monitor live jobs.
      </p>
    </div>

    <div class="ui-showcase-grid">
      
      <!-- Left: Real HTML/CSS Shop Dashboard & Queue Mockup -->
      <div class="mockup-window">
        <div class="mockup-header">
          <span class="mockup-dot red"></span>
          <span class="mockup-dot yellow"></span>
          <span class="mockup-dot green"></span>
          <div class="mockup-url">https://primeprint.webd.co.in/shop/dashboard.php</div>
        </div>

        <div class="mockup-content">
          <!-- Shop Header Inside Mockup -->
          <div class="d-flex justify-content-between align-items-center mb-3 pb-2 border-bottom">
            <div>
              <div class="fw-bold text-dark font-display" style="font-size: 1.05rem;">ABC Digital Printing Counter</div>
              <div class="small text-muted font-mono" style="font-size: 0.72rem;">SLUG: /p/abc-digital-printing · AGENT: ONLINE</div>
            </div>
            <span class="badge bg-success-subtle text-success border font-mono" style="font-size: 0.7rem;">
              <i class="bi bi-circle-fill me-1" style="font-size: 0.5rem;"></i>AGENT ACTIVE
            </span>
          </div>

          <!-- KPI Mini Tiles -->
          <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin-bottom: 16px;">
            <div style="background: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 8px; padding: 10px;">
              <div style="font-size: 0.68rem; color: #64748B; font-weight: 600;">TODAY'S REVENUE</div>
              <div style="font-family: var(--font-mono); font-size: 1.1rem; font-weight: 700; color: #0F172A;">₹1,420.00</div>
            </div>
            <div style="background: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 8px; padding: 10px;">
              <div style="font-size: 0.68rem; color: #64748B; font-weight: 600;">JOBS PRINTED</div>
              <div style="font-family: var(--font-mono); font-size: 1.1rem; font-weight: 700; color: #1D4ED8;">84 Jobs</div>
            </div>
            <div style="background: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 8px; padding: 10px;">
              <div style="font-size: 0.68rem; color: #64748B; font-weight: 600;">QUEUE WAITING</div>
              <div style="font-family: var(--font-mono); font-size: 1.1rem; font-weight: 700; color: #059669;">0 Pending</div>
            </div>
          </div>

          <!-- Live Job Queue Snippet -->
          <div style="border: 1px solid #E2E8F0; border-radius: 8px; overflow: hidden;">
            <div style="background: #F1F5F9; padding: 6px 10px; font-size: 0.7rem; font-weight: 700; color: #475569; font-family: var(--font-mono);">
              ACTIVE PRINT JOBS (FIFO AUTO-DISPATCH)
            </div>
            <table style="width: 100%; border-collapse: collapse; font-size: 0.75rem;">
              <tr style="border-bottom: 1px solid #F1F5F9;">
                <td style="padding: 8px 10px; font-family: var(--font-mono); font-weight: 700;">#00482</td>
                <td style="padding: 8px 10px;">Resume_Final.pdf (2 pgs)</td>
                <td style="padding: 8px 10px; font-family: var(--font-mono);">A4 · B&W</td>
                <td style="padding: 8px 10px; font-weight: 700;">₹4.00</td>
                <td style="padding: 8px 10px; text-align: right;"><span class="badge bg-success-subtle text-success" style="font-size: 0.65rem;">PRINTED</span></td>
              </tr>
              <tr>
                <td style="padding: 8px 10px; font-family: var(--font-mono); font-weight: 700;">#00483</td>
                <td style="padding: 8px 10px;">Project_Thesis.pdf (45 pgs)</td>
                <td style="padding: 8px 10px; font-family: var(--font-mono);">A4 · Color</td>
                <td style="padding: 8px 10px; font-weight: 700;">₹450.00</td>
                <td style="padding: 8px 10px; text-align: right;"><span class="badge bg-primary-subtle text-primary" style="font-size: 0.65rem;">PRINTING</span></td>
              </tr>
            </table>
          </div>
        </div>
      </div>

      <!-- Right: Feature Explanations -->
      <div class="ui-feature-list">
        
        <div class="ui-feature-item">
          <div class="ui-feature-icon">
            <i class="bi bi-currency-rupee"></i>
          </div>
          <div>
            <h4 class="fw-bold text-dark font-display mb-1">Server-Side Dynamic Pricing</h4>
            <p class="small text-muted mb-0">
              Configure per-page pricing rules for A4/A3, Black & White vs. Color, Single vs. Duplex. The server validates every PDF page to prevent calculation disputes.
            </p>
          </div>
        </div>

        <div class="ui-feature-item">
          <div class="ui-feature-icon">
            <i class="bi bi-qr-code"></i>
          </div>
          <div>
            <h4 class="fw-bold text-dark font-display mb-1">Instant Standee QR Generator</h4>
            <p class="small text-muted mb-0">
              Download and print high-resolution 800×1000 counter standee posters directly from the shop portal to place on your counter desk.
            </p>
          </div>
        </div>

        <div class="ui-feature-item">
          <div class="ui-feature-icon">
            <i class="bi bi-wallet2"></i>
          </div>
          <div>
            <h4 class="fw-bold text-dark font-display mb-1">Direct Shop Razorpay Keys</h4>
            <p class="small text-muted mb-0">
              Shops can connect their own direct Razorpay Key ID and Secret so customer payments settle directly into their own bank account.
            </p>
          </div>
        </div>

        <div class="ui-feature-item">
          <div class="ui-feature-icon">
            <i class="bi bi-shield-check"></i>
          </div>
          <div>
            <h4 class="fw-bold text-dark font-display mb-1">No Unpaid Prints (Zero Leakage)</h4>
            <p class="small text-muted mb-0">
              The Print Agent strictly requires a cryptographically verified Razorpay payment signature before any paper touches the printer drum.
            </p>
          </div>
        </div>

      </div>

    </div>

  </div>
</section>

<!-- ========================================================= -->
<!-- 5. ARCHITECTURE & TECHNOLOGY: The Hardware Bridge -->
<!-- ========================================================= -->
<section class="section-arch" id="architecture">
  <div class="container-xl" style="max-width: 1240px; margin: 0 auto;">
    
    <div class="section-header-center">
      <span class="section-tag">HARDWARE INTEGRATION BRIDGE</span>
      <h2 class="section-title">From a QR code to a printed page.</h2>
      <p class="section-desc">
        The cloud manages customer uploads, cryptographic payment signatures, and queues. The lightweight Windows Print Agent bridges the cloud queue directly to the physical printers in the shop.
      </p>
    </div>

    <div class="arch-card">
      
      <div class="arch-flow-row">
        
        <!-- Step 1: Customer Phone -->
        <div class="arch-node">
          <div style="font-size: 2rem; color: #1D4ED8;"><i class="bi bi-phone"></i></div>
          <div class="arch-node-title">1. Customer Mobile</div>
          <p class="arch-node-desc">Mobile browser uploads PDF & initiates 1-click Razorpay payment</p>
        </div>

        <!-- Connection 1 -->
        <div class="arch-arrow">
          <i class="bi bi-arrow-right"></i>
          <span class="arch-arrow-label">HTTPS JSON</span>
        </div>

        <!-- Step 2: PrimePrint Cloud Engine -->
        <div class="arch-node primary">
          <div style="font-size: 2rem; color: #1D4ED8;"><i class="bi bi-cloud-check-fill"></i></div>
          <div class="arch-node-title">2. PrimePrint Cloud Engine</div>
          <p class="arch-node-desc">Validates upload, computes price, verifies payment signature & queues job</p>
        </div>

        <!-- Connection 2 -->
        <div class="arch-arrow">
          <i class="bi bi-arrow-right"></i>
          <span class="arch-arrow-label">BEARER TOKEN</span>
        </div>

        <!-- Step 3: Windows Print Agent -->
        <div class="arch-node">
          <div style="font-size: 2rem; color: #059669;"><i class="bi bi-pc-display"></i></div>
          <div class="arch-node-title">3. Desktop Print Agent</div>
          <p class="arch-node-desc">Polls queue, fetches PDF file, configures duplex/color & spools to printer</p>
        </div>

      </div>

      <!-- Summary Info Pill -->
      <div class="d-flex flex-wrap align-items-center justify-content-between p-3 bg-light rounded-3 border mt-4">
        <div class="d-flex align-items-center gap-2">
          <i class="bi bi-info-circle-fill text-primary"></i>
          <span class="small text-muted"><strong>Customer does NOT choose printers.</strong> The shop operator pairs their default office printer in the Print Agent settings.</span>
        </div>
        <span class="paper-tag font-mono">STANDALONE WIN32 SPOOLER</span>
      </div>

    </div>

  </div>
</section>

<!-- ========================================================= -->
<!-- 6. FOR PRINTING SHOPS SECTION -->
<!-- ========================================================= -->
<section class="section-problem" id="for-shops" style="background-color: #F5F3EE;">
  <div class="container-xl" style="max-width: 1240px; margin: 0 auto;">
    
    <div class="section-header-center">
      <span class="section-tag">BUSINESS BENEFITS FOR SHOP OWNERS</span>
      <h2 class="section-title">Your shop runs. PrimePrint handles the queue.</h2>
      <p class="section-desc">
        Designed from the ground up for high-traffic stationery stores, college xerox counters, and commercial digital print shops.
      </p>
    </div>

    <div class="problem-grid">
      
      <!-- Left: Real Shop Owner Photography -->
      <div class="crop-frame">
        <div class="paper-card p-2" style="overflow: hidden; border-radius: 18px;">
          <img src="<?= asset_url('assets/images/marketing/shop_owner_portrait.jpg') ?>" alt="Indian Print Shop Owner" style="width: 100%; height: 440px; object-fit: cover; border-radius: 12px; display: block;">
        </div>
      </div>

      <!-- Right: Shop Owner Value Matrix -->
      <div class="d-flex flex-column gap-3">
        
        <div class="paper-card p-3">
          <div class="d-flex align-items-center gap-3">
            <div class="ui-feature-icon" style="background: #ECFDF5; color: #059669; border-color: #A7F3D0;">
              <i class="bi bi-clock-history"></i>
            </div>
            <div>
              <h5 class="fw-bold text-dark font-display mb-1">Save 4+ Minutes Per Customer</h5>
              <p class="small text-muted mb-0">Customers configure their own print preferences on their phone, cutting down counter conversation time.</p>
            </div>
          </div>
        </div>

        <div class="paper-card p-3">
          <div class="d-flex align-items-center gap-3">
            <div class="ui-feature-icon" style="background: #EFF6FF; color: #1D4ED8; border-color: #BFDBFE;">
              <i class="bi bi-cash-stack"></i>
            </div>
            <div>
              <h5 class="fw-bold text-dark font-display mb-1">100% Cashless UPI Settlement</h5>
              <p class="small text-muted mb-0">Direct integration with Razorpay UPI, GPay, PhonePe, and Paytm eliminates loose change hassles.</p>
            </div>
          </div>
        </div>

        <div class="paper-card p-3">
          <div class="d-flex align-items-center gap-3">
            <div class="ui-feature-icon" style="background: #FFFBEB; color: #D97706; border-color: #FDE68A;">
              <i class="bi bi-shield-lock"></i>
            </div>
            <div>
              <h5 class="fw-bold text-dark font-display mb-1">No USB Virus Infection Risks</h5>
              <p class="small text-muted mb-0">Stop plugging infected customer flash drives and memory cards into your main business PC.</p>
            </div>
          </div>
        </div>

        <div class="paper-card p-3">
          <div class="d-flex align-items-center gap-3">
            <div class="ui-feature-icon" style="background: #F5F3FF; color: #7C3AED; border-color: #DDD6FE;">
              <i class="bi bi-file-earmark-spreadsheet"></i>
            </div>
            <div>
              <h5 class="fw-bold text-dark font-display mb-1">Complete Digital Accounting</h5>
              <p class="small text-muted mb-0">Track all print jobs, revenue metrics, and auto-generated customer invoices in one unified dashboard.</p>
            </div>
          </div>
        </div>

      </div>

    </div>

  </div>
</section>

<!-- ========================================================= -->
<!-- 7. BEFORE VS. AFTER COMPARISON -->
<!-- ========================================================= -->
<section class="section-comparison">
  <div class="container-xl" style="max-width: 1240px; margin: 0 auto;">
    
    <div class="section-header-center">
      <span class="section-tag">THE TRANSFORMATION</span>
      <h2 class="section-title">Before vs. After PrimePrint</h2>
      <p class="section-desc">See how replacing manual counter steps with automated QR routing transforms your daily shop operations.</p>
    </div>

    <div class="comparison-grid">
      
      <!-- Before -->
      <div class="comparison-col before">
        <span class="comparison-badge">WITHOUT PRIMEPRINT</span>
        <h3 class="fw-bold text-dark font-display mb-3">The Manual Counter Chaos</h3>
        
        <ul style="list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 14px; font-size: 0.95rem;">
          <li class="d-flex align-items-start gap-2">
            <i class="bi bi-x-circle-fill text-danger mt-1"></i>
            <span>Customer sends files across multiple personal WhatsApp chats</span>
          </li>
          <li class="d-flex align-items-start gap-2">
            <i class="bi bi-x-circle-fill text-danger mt-1"></i>
            <span>Operator manually searches download folders for file names</span>
          </li>
          <li class="d-flex align-items-start gap-2">
            <i class="bi bi-x-circle-fill text-danger mt-1"></i>
            <span>Manual rate calculations cause frequent customer price disputes</span>
          </li>
          <li class="d-flex align-items-start gap-2">
            <i class="bi bi-x-circle-fill text-danger mt-1"></i>
            <span>Printed documents sit uncollected without upfront payment</span>
          </li>
          <li class="d-flex align-items-start gap-2">
            <i class="bi bi-x-circle-fill text-danger mt-1"></i>
            <span>Average counter service time: <strong>6 to 8 minutes</strong></span>
          </li>
        </ul>
      </div>

      <!-- After -->
      <div class="comparison-col after">
        <span class="comparison-badge">WITH PRIMEPRINT</span>
        <h3 class="fw-bold text-dark font-display mb-3">The Automated QR Counter</h3>
        
        <ul style="list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 14px; font-size: 0.95rem;">
          <li class="d-flex align-items-start gap-2">
            <i class="bi bi-check-circle-fill text-success mt-1"></i>
            <span>Customer scans standee QR and uploads file instantly in browser</span>
          </li>
          <li class="d-flex align-items-start gap-2">
            <i class="bi bi-check-circle-fill text-success mt-1"></i>
            <span>Automatic page count detection and verified per-page pricing</span>
          </li>
          <li class="d-flex align-items-start gap-2">
            <i class="bi bi-check-circle-fill text-success mt-1"></i>
            <span>1-Click Razorpay UPI / Card payment before printing begins</span>
          </li>
          <li class="d-flex align-items-start gap-2">
            <i class="bi bi-check-circle-fill text-success mt-1"></i>
            <span>Print Agent silently routes paid file straight to desktop printer</span>
          </li>
          <li class="d-flex align-items-start gap-2">
            <i class="bi bi-check-circle-fill text-success mt-1"></i>
            <span>Average counter service time: <strong>under 30 seconds</strong></span>
          </li>
        </ul>
      </div>

    </div>

  </div>
</section>

<!-- ========================================================= -->
<!-- 8. TRUST & ARCHITECTURAL INTEGRITY -->
<!-- ========================================================= -->
<section class="section-trust">
  <div class="container-xl" style="max-width: 1240px; margin: 0 auto;">
    
    <div class="section-header-center">
      <span class="section-tag">ENGINEERED FOR STABILITY</span>
      <h2 class="section-title">Built around the way print shops actually work.</h2>
      <p class="section-desc">Transparent architecture, zero gimmicks, and complete operational security.</p>
    </div>

    <div class="trust-grid">
      
      <div class="trust-card">
        <div class="trust-icon"><i class="bi bi-shield-check"></i></div>
        <h4 class="fw-bold text-dark font-display mb-1">Razorpay Verified</h4>
        <p class="small text-muted mb-0">Cryptographic HMAC-SHA256 signature verification guarantees every print job is settled before spooling.</p>
      </div>

      <div class="trust-card">
        <div class="trust-icon"><i class="bi bi-hdd-network"></i></div>
        <h4 class="fw-bold text-dark font-display mb-1">Local Spooling Bridge</h4>
        <p class="small text-muted mb-0">The Windows Print Agent uses Windows Print Spooler APIs (`win32print`), ensuring compatibility with all laser & thermal printers.</p>
      </div>

      <div class="trust-card">
        <div class="trust-icon"><i class="bi bi-layers-half"></i></div>
        <h4 class="fw-bold text-dark font-display mb-1">Multi-Shop Isolation</h4>
        <p class="small text-muted mb-0">Every shop operates on a dedicated URL slug (`/p/{slug}`) with isolated print queues, custom pricing, and staff accounts.</p>
      </div>

    </div>

  </div>
</section>

<!-- ========================================================= -->
<!-- 9. FREQUENTLY ASKED QUESTIONS (FAQ) -->
<!-- ========================================================= -->
<section class="section-faq" id="faq">
  <div class="faq-container">
    
    <div class="section-header-center">
      <span class="section-tag">FREQUENTLY ASKED QUESTIONS</span>
      <h2 class="section-title">Everything you need to know.</h2>
      <p class="section-desc">Straightforward answers about hardware, customer flow, and payments.</p>
    </div>

    <!-- FAQ Item 1 -->
    <div class="faq-item active">
      <button type="button" class="faq-question">
        <span>What is PrimePrint?</span>
        <i class="bi bi-chevron-down faq-icon"></i>
      </button>
      <div class="faq-answer">
        PrimePrint is a multi-tenant cloud automation system built for commercial printing and xerox shops. It allows customers to scan a counter QR code, upload documents on their phone, pay online via UPI, and have their documents print automatically without manual file transfer.
      </div>
    </div>

    <!-- FAQ Item 2 -->
    <div class="faq-item">
      <button type="button" class="faq-question">
        <span>How does a customer print using PrimePrint?</span>
        <i class="bi bi-chevron-down faq-icon"></i>
      </button>
      <div class="faq-answer">
        The customer opens their phone camera or any UPI app, scans the QR standee on the counter, selects their PDF or image file, picks B&W or Color and copies, and taps ⚡ Pay & Print. The document prints automatically at the counter.
      </div>
    </div>

    <!-- FAQ Item 3 -->
    <div class="faq-item">
      <button type="button" class="faq-question">
        <span>Does the customer need to install an app or register an account?</span>
        <i class="bi bi-chevron-down faq-icon"></i>
      </button>
      <div class="faq-answer">
        No. The customer portal runs entirely in the mobile web browser. No Android APK, iOS app, login, or registration is required.
      </div>
    </div>

    <!-- FAQ Item 4 -->
    <div class="faq-item">
      <button type="button" class="faq-question">
        <span>Does the customer select the physical printer?</span>
        <i class="bi bi-chevron-down faq-icon"></i>
      </button>
      <div class="faq-answer">
        No. Customers only choose printing preferences (Paper size, B&W/Color, Copies). The shop operator configures their default physical printer in their paired Windows Print Agent.
      </div>
    </div>

    <!-- FAQ Item 5 -->
    <div class="faq-item">
      <button type="button" class="faq-question">
        <span>How does the shop connect its printers to the cloud?</span>
        <i class="bi bi-chevron-down faq-icon"></i>
      </button>
      <div class="faq-answer">
        The shop runs the lightweight PrimePrint Desktop Agent on their Windows PC. The agent syncs installed Windows printers, listens for new paid jobs, and sends them directly to the selected printer.
      </div>
    </div>

    <!-- FAQ Item 6 -->
    <div class="faq-item">
      <button type="button" class="faq-question">
        <span>How are payments handled?</span>
        <i class="bi bi-chevron-down faq-icon"></i>
      </button>
      <div class="faq-answer">
        Payments are processed securely through Razorpay supporting Google Pay, PhonePe, Paytm, BHIM UPI, Netbanking, and Cards. Shops can also configure their own Razorpay keys for direct bank settlement.
      </div>
    </div>

    <!-- FAQ Item 7 -->
    <div class="faq-item">
      <button type="button" class="faq-question">
        <span>What happens if the internet goes down while a customer is printing?</span>
        <i class="bi bi-chevron-down faq-icon"></i>
      </button>
      <div class="faq-answer">
        Jobs remain safely stored in the cloud queue with their verified payment status. As soon as the shop PC reconnects, the Print Agent automatically pulls and prints all pending jobs in chronological sequence.
      </div>
    </div>

    <!-- FAQ Item 8 -->
    <div class="faq-item">
      <button type="button" class="faq-question">
        <span>Can a shop customize its printing rates?</span>
        <i class="bi bi-chevron-down faq-icon"></i>
      </button>
      <div class="faq-answer">
        Yes. In the Shop Manager portal, owners can customize per-page pricing for A4, A3, single-sided, double-sided, and color modes at any time.
      </div>
    </div>

  </div>
</section>

<!-- ========================================================= -->
<!-- 10. FINAL CALL TO ACTION BANNER -->
<!-- ========================================================= -->
<section class="section-cta">
  <div class="cta-box">
    
    <div>
      <h2 class="cta-title font-display">
        Your shop. Your printers. <br>
        A simpler way to print.
      </h2>
      <p class="cta-desc">
        Give customers a faster way to print, while your team spends less time managing files and cables at the counter.
      </p>
      <div class="d-flex flex-wrap gap-3">
        <button type="button" class="btn-pp-blue" onclick="openContactModal()">
          <span>Get Started with PrimePrint</span>
          <i class="bi bi-arrow-right"></i>
        </button>
        <button type="button" class="btn-pp-outline" style="border-color: rgba(255,255,255,0.3); color: #FFFDF8;" onclick="openLoginModal()">
          <i class="bi bi-box-arrow-in-right"></i>
          <span>Shop Owner Login</span>
        </button>
      </div>
    </div>

    <div>
      <img src="<?= asset_url('assets/images/marketing/print_output_macro.jpg') ?>" alt="Fresh Printed Documents Output" class="cta-image">
    </div>

  </div>
</section>

<?php require_once __DIR__ . '/includes/marketing-footer.php'; ?>
