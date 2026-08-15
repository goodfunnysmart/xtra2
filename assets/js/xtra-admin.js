(function () {
	'use strict';
	document.addEventListener('click', function (ev) {
		var t = ev.target.closest('.xtra-cancel-btn');
		if (!t) {
			return;
		}
		if (!window.confirm('Schedule this subscription to cancel at the end of the calendar month? The hours stay sponsored until then.')) {
			ev.preventDefault();
		}
	});
})();
