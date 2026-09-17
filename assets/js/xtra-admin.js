(function () {
	'use strict';
	document.addEventListener('click', function (ev) {
		var t = ev.target.closest('a[href*="action=xtra_schedule_cancel"]');
		if (!t) {
			return;
		}
		if (!window.confirm('Schedule this subscription to cancel at the end of the calendar month? The hours stay sponsored until then.')) {
			ev.preventDefault();
		}
	});

	var selectBtn = document.getElementById('xtra_receipt_logo_select');
	var removeBtn = document.getElementById('xtra_receipt_logo_remove');
	var idInput = document.getElementById('xtra_receipt_logo_id');
	var preview = document.getElementById('xtra_receipt_logo_preview');
	if (!selectBtn || !idInput || typeof wp === 'undefined' || !wp.media) {
		return;
	}

	var frame;
	selectBtn.addEventListener('click', function (ev) {
		ev.preventDefault();
		if (frame) {
			frame.open();
			return;
		}
		frame = wp.media({
			title: 'Select organisation logo',
			button: { text: 'Use this logo' },
			multiple: false,
			library: { type: 'image' }
		});
		frame.on('select', function () {
			var attachment = frame.state().get('selection').first().toJSON();
			idInput.value = attachment.id || 0;
			var url = (attachment.sizes && attachment.sizes.medium && attachment.sizes.medium.url)
				? attachment.sizes.medium.url
				: attachment.url;
			if (preview) {
				preview.innerHTML = url
					? '<img src="' + url + '" alt="" style="max-width:180px;height:auto;display:block;" />'
					: '';
			}
			if (removeBtn) {
				removeBtn.style.display = '';
			}
		});
		frame.open();
	});

	if (removeBtn) {
		removeBtn.addEventListener('click', function (ev) {
			ev.preventDefault();
			idInput.value = '0';
			if (preview) {
				preview.innerHTML = '';
			}
			removeBtn.style.display = 'none';
		});
	}
})();
