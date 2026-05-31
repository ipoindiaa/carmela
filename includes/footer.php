        </div><!-- /.page-content -->
    </main>
</div><!-- /.app-container -->

<?php $jsVersion = @filemtime(__DIR__ . '/../assets/js/app.js') ?: APP_VERSION; ?>
<script src="<?= APP_URL ?>assets/js/app.js?v=<?= $jsVersion ?>"></script>
</body>
</html>
