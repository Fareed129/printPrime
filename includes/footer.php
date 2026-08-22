<?php
/**
 * PrimePrint Global Footer Include
 */

$currentUser = current_user();
?>
  <?php if ($currentUser): ?>
      </main>
    </div> <!-- /.app-layout -->
  <?php endif; ?>

  <footer class="mt-auto py-3 bg-white border-top text-center text-muted small">
    <div class="container">
      <span>&copy; <?= date('Y') ?> <strong><?= APP_NAME ?></strong> — Multi-Tenant Cloud Printing Platform.</span>
    </div>
  </footer>

  <!-- Bootstrap 5.3 JS Bundle -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>

  <!-- QRCode.js Generator -->
  <script src="<?= APP_URL ?>/assets/js/qrcode.min.js"></script>

  <!-- PrimePrint App JS -->
  <script src="<?= APP_URL ?>/assets/js/app.js"></script>
</body>
</html>
