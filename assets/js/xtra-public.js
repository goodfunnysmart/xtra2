(function () {
	'use strict';

	function ready(fn) {
		if (document.readyState === 'loading') {
			document.addEventListener('DOMContentLoaded', fn);
		} else {
			fn();
		}
	}

	function formatAud(cents) {
		var n = (cents / 100).toFixed(2);
		return '$' + n.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
	}

	function showError(root, message) {
		root.querySelectorAll('[data-error]').forEach(function (el) {
			el.hidden = !message;
			el.textContent = message || '';
		});
	}

	ready(function () {
		var root = document.querySelector('.xtra');
		var cfg = window.xtraPublic;
		if (!cfg && root) {
			cfg = {
				restUrl: root.getAttribute('data-rest-url') || '',
				nonce: root.getAttribute('data-nonce') || '',
				positionId: parseInt(root.getAttribute('data-position'), 10) || 0,
				rateCents: parseInt(root.getAttribute('data-rate'), 10) || 0,
				configured: root.getAttribute('data-configured') === '1',
				returnUrl: root.getAttribute('data-return-url') || window.location.href.split('#')[0],
				termsUrl: root.getAttribute('data-terms-url') || '',
				i18n: {
					hour: 'hour',
					hours: 'hours',
					error: 'Something went wrong. Please try again.',
					notConfigured: 'Payments are not configured yet. You can view and select hours, but checkout is unavailable.'
				}
			};
		}
		if (!cfg || !root) {
			return;
		}

		var selected = {};
		var lockToken = '';
		var selectPanel = root.querySelector('[data-panel="select"]');
		var checkoutPanel = root.querySelector('[data-panel="checkout"]');
		var continueBtn = root.querySelector('[data-action="continue"]');
		var backBtn = root.querySelector('[data-action="back"]');
		var form = root.querySelector('[data-form="checkout"]');
		var countEl = root.querySelector('[data-count]');
		var countLabel = root.querySelector('[data-count-label]');
		var totalEl = root.querySelector('[data-total]');
		var summaryEl = root.querySelector('[data-checkout-summary]');

		function selectedList() {
			return Object.keys(selected).map(function (key) {
				var parts = key.split('-');
				return { dow: parseInt(parts[0], 10), hour: parseInt(parts[1], 10) };
			});
		}

		function updateSidebar() {
			var list = selectedList();
			var n = list.length;
			var cents = n * (cfg.rateCents || 0);
			if (countEl) {
				countEl.textContent = String(n);
			}
			if (countLabel) {
				countLabel.textContent = n === 1 ? cfg.i18n.hour : cfg.i18n.hours;
			}
			if (totalEl) {
				totalEl.textContent = formatAud(cents);
			}
			if (continueBtn) {
				continueBtn.disabled = !cfg.configured || n === 0;
			}
			if (summaryEl) {
				summaryEl.textContent = n
					? n + ' ' + (n === 1 ? cfg.i18n.hour : cfg.i18n.hours) + ' · ' + formatAud(cents) + ' / mo'
					: '';
			}
		}

		root.querySelectorAll('.xtra-cell-btn[data-status="available"]').forEach(function (btn) {
			btn.addEventListener('click', function () {
				var dow = btn.getAttribute('data-dow');
				var hour = btn.getAttribute('data-hour');
				var key = dow + '-' + hour;
				if (selected[key]) {
					delete selected[key];
					btn.setAttribute('aria-pressed', 'false');
					btn.closest('.xtra-cell').classList.remove('xtra-cell-selected');
				} else {
					selected[key] = true;
					btn.setAttribute('aria-pressed', 'true');
					btn.closest('.xtra-cell').classList.add('xtra-cell-selected');
				}
				showError(root, '');
				updateSidebar();
			});
		});

		function rest(path, body) {
			return fetch(cfg.restUrl + path, {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': cfg.nonce
				},
				body: JSON.stringify(body)
			}).then(function (res) {
				return res.json().then(function (data) {
					if (!res.ok) {
						var msg = (data && (data.message || (data.data && data.data.message))) || cfg.i18n.error;
						throw new Error(msg);
					}
					return data;
				});
			});
		}

		if (continueBtn) {
			continueBtn.addEventListener('click', function () {
				if (!cfg.configured) {
					showError(selectPanel, cfg.i18n.notConfigured);
					return;
				}
				var cells = selectedList();
				if (!cells.length) {
					return;
				}
				continueBtn.disabled = true;
				showError(root, '');
				rest('lock', {
					position_id: cfg.positionId,
					cells: cells
				})
					.then(function (data) {
						lockToken = data.token;
						if (selectPanel) {
							selectPanel.hidden = true;
						}
						if (checkoutPanel) {
							checkoutPanel.hidden = false;
						}
						updateSidebar();
					})
					.catch(function (err) {
						showError(selectPanel, err.message || cfg.i18n.error);
						continueBtn.disabled = false;
					});
			});
		}

		if (backBtn) {
			backBtn.addEventListener('click', function () {
				lockToken = '';
				if (checkoutPanel) {
					checkoutPanel.hidden = true;
				}
				if (selectPanel) {
					selectPanel.hidden = false;
				}
				updateSidebar();
			});
		}

		if (form) {
			form.addEventListener('submit', function (ev) {
				ev.preventDefault();
				if (!lockToken) {
					showError(checkoutPanel, cfg.i18n.error);
					return;
				}
				var fd = new FormData(form);
				if (!fd.get('terms')) {
					showError(checkoutPanel, cfg.i18n.error);
					return;
				}
				var submit = form.querySelector('button[type="submit"]');
				if (submit) {
					submit.disabled = true;
				}
				showError(root, '');
				rest('checkout', {
					token: lockToken,
					first_name: fd.get('first_name') || '',
					last_name: fd.get('last_name') || '',
					email: fd.get('email') || '',
					address: fd.get('address') || '',
					suburb: fd.get('suburb') || '',
					state: fd.get('state') || '',
					postcode: fd.get('postcode') || '',
					phone: fd.get('phone') || '',
					message: fd.get('message') || '',
					opt_in_news: fd.get('opt_in_news') ? 1 : 0,
					opt_in_hour_start: fd.get('opt_in_hour_start') ? 1 : 0,
					terms: true,
					return_url: cfg.returnUrl
				})
					.then(function (data) {
						if (data.url) {
							window.location.href = data.url;
							return;
						}
						throw new Error(cfg.i18n.error);
					})
					.catch(function (err) {
						showError(checkoutPanel, err.message || cfg.i18n.error);
						if (submit) {
							submit.disabled = false;
						}
					});
			});
		}

		updateSidebar();
	});
})();
