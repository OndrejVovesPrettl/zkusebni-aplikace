
<!-- Layout footer – includes/layout_end.php -->
</div><!-- .main -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<?php
$mainJsPath = __DIR__ . '/../assets/js/main.js';
$mainJsVersion = is_file($mainJsPath) ? (string)filemtime($mainJsPath) : APP_VERSION;
?>
<script src="<?= APP_URL ?>/assets/js/main.js?v=<?= urlencode($mainJsVersion) ?>"></script>
</body>
</html>
