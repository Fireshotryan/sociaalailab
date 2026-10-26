<?php
if (!isset($navPrefix)) {
    $navPrefix = '';
}
?>
<nav class="navbar onest-font">
    <div class="nav-container">
        <button type="button" id="mobile-menu-toggle" class="hamburger" aria-label="Toggle navigation" aria-controls="nav-menu" aria-expanded="false">
            &#9776;
        </button>

        <ul id="nav-menu" class="nav-menu ">
            <li><a href="<?php echo htmlspecialchars($navPrefix); ?>index.php">Voorpagina</a></li>
            <li><a href="<?php echo htmlspecialchars($navPrefix); ?>agenda.php">Agenda</a></li>
            <li><a href="<?php echo htmlspecialchars($navPrefix); ?>nieuws.php">Nieuws</a></li>
			<li><a href="<?php echo htmlspecialchars($navPrefix); ?>over.php">Voor wie?</a></li>

            <li class="dropdown">
                <button type="button" class="dropbtn" aria-controls="program-dropdown" aria-expanded="false">Wat doen we?<span class="dropdown-caret" aria-hidden="true"></span></button>
                <ul id="program-dropdown" class="dropdown-content">
                    <li><a href="<?php echo htmlspecialchars($navPrefix); ?>programma/kennis.php">Kennis & vaardigheden</a></li>
                    <li><a href="<?php echo htmlspecialchars($navPrefix); ?>programma/actie.php">Actie, onderzoek & ontwerp</a></li>
                    <li><a href="<?php echo htmlspecialchars($navPrefix); ?>programma/faciliteit.php">Faciliteit van het Lab</a></li>
                </ul>
            </li>

            <li><a href="<?php echo htmlspecialchars($navPrefix); ?>verantwoord-ai.php">Verantwoorde AI</a></li>
            <li class="dropdown">
                <button type="button" class="dropbtn" aria-controls="about-dropdown" aria-expanded="false">Wie zijn we?<span class="dropdown-caret" aria-hidden="true"></span></button>
                <ul id="about-dropdown" class="dropdown-content">
                    <li><a href="<?php echo htmlspecialchars($navPrefix); ?>wie-zijn-we.php">Overzicht</a></li>
                    <li><a href="<?php echo htmlspecialchars($navPrefix); ?>ons-team.php">Ons team</a></li>
                </ul>
            </li>
            <li><a href="<?php echo htmlspecialchars($navPrefix); ?>contact.php">Contact</a></li>
            <?php if (!empty($_SESSION['can_access_admin'])): ?>
                <li><a href="<?php echo htmlspecialchars($navPrefix); ?>admin.php">Admin</a></li>
            <?php endif; ?>
        </ul>
    </div>
</nav>

<script>
    (function() {
        const toggle = document.getElementById('mobile-menu-toggle');
        const menu = document.getElementById('nav-menu');
        if (!toggle || !menu) return;

        const dropdownButtons = Array.from(menu.querySelectorAll('.dropdown .dropbtn'));

        function closeDropdown(button, restoreFocus) {
            const content = document.getElementById(button.getAttribute('aria-controls'));
            button.setAttribute('aria-expanded', 'false');
            button.classList.remove('open');
            if (content) content.classList.remove('show');
            if (restoreFocus) button.focus();
        }

        function closeAllDropdowns() {
            dropdownButtons.forEach(function(button) {
                closeDropdown(button, false);
            });
        }

        function openMenu() {
            menu.classList.add('show');
            toggle.setAttribute('aria-expanded', 'true');
        }

        function closeMenu(restoreFocus) {
            menu.classList.remove('show');
            toggle.setAttribute('aria-expanded', 'false');
            closeAllDropdowns();
            if (restoreFocus) toggle.focus();
        }

        toggle.addEventListener('click', function() {
            if (toggle.getAttribute('aria-expanded') === 'true') {
                closeMenu(false);
            } else {
                openMenu();
            }
        });

        dropdownButtons.forEach(function(button) {
            const content = document.getElementById(button.getAttribute('aria-controls'));
            if (content) content.classList.remove('show');

            button.addEventListener('click', function() {
                const willOpen = button.getAttribute('aria-expanded') !== 'true';
                closeAllDropdowns();
                if (willOpen && content) {
                    button.setAttribute('aria-expanded', 'true');
                    button.classList.add('open');
                    content.classList.add('show');
                }
            });
        });

        document.addEventListener('click', function(event) {
            if (!menu.contains(event.target) && !toggle.contains(event.target)) {
                closeMenu(false);
            }
        });

        document.addEventListener('keydown', function(event) {
            if (event.key !== 'Escape') return;

            const openDropdown = dropdownButtons.find(function(button) {
                return button.getAttribute('aria-expanded') === 'true';
            });
            if (openDropdown) {
                event.preventDefault();
                closeDropdown(openDropdown, true);
            } else if (toggle.getAttribute('aria-expanded') === 'true') {
                event.preventDefault();
                closeMenu(true);
            }
        });
    })();
</script>
