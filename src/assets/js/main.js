// main.js - small UI helpers
(function () {
	document.addEventListener('DOMContentLoaded', function () {
		var avatar = document.querySelector('.nav-avatar');
		if (!avatar) return;

		function closeAvatar() {
			avatar.classList.remove('open');
			avatar.setAttribute('aria-expanded', 'false');
		}

		function openAvatar() {
			avatar.classList.add('open');
			avatar.setAttribute('aria-expanded', 'true');
		}

		avatar.addEventListener('click', function (e) {
			e.stopPropagation();
			if (avatar.classList.contains('open')) closeAvatar();
			else openAvatar();
		});

		document.addEventListener('click', function () { closeAvatar(); });
		document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeAvatar(); });
	});
})();