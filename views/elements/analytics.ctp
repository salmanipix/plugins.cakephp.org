<?php if (Configure::read() == 0 && Authsome::get('group') != 'admin' ) : ?>
	<script type="text/javascript">
		var gaJsHost = (("https:" == document.location.protocol) ? "https://ssl." : "http://www.");
		document.write(unescape("%3Cscript src='" + gaJsHost + "google-analytics.com/ga.js' type='text/javascript'%3E%3C/script%3E"));
	</script>
	<script type="text/javascript">
		try {
			var pageTracker = _gat._getTracker("UA-8668344-5");
			pageTracker._trackPageview();
		} catch(err) {}
	</script>
<?php endif; ?>