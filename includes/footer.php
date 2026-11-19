        </div><!-- /.page-content -->
    </main>
</div><!-- /.app-container -->

<?php $jsVersion = @filemtime(__DIR__ . '/../assets/js/app.js') ?: APP_VERSION; ?>
<script src="<?= APP_URL ?>assets/js/app.js?v=<?= $jsVersion ?>"></script>
<script src="<?= APP_URL ?>assets/js/person-suggestions.js?v=<?= @filemtime(__DIR__ . '/../assets/js/person-suggestions.js') ?: APP_VERSION ?>" data-base="<?= clean(APP_URL) ?>"></script>
</body>
</html>
