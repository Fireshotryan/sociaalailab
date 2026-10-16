<?php
$scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_FILENAME']));
$rootDir = str_replace('\\', '/', dirname(__FILE__));
$imgPrefix = ($scriptDir === $rootDir) ? 'images/' : '../images/';
?>
<footer class="site-footer onest-font">
    <div class="footer-container">
        <h3>In samenwerking met:</h3>
        <div class="partners">
            <img alt="Gemeente Rotterdam" src="<?php echo $imgPrefix; ?>GemeenteRotterdam.png">
            <img alt="Erasmus Centre for Data Analytics" src="<?php echo $imgPrefix; ?>ECDA.png">
            <img alt="Hogeschool Rotterdam" src="<?php echo $imgPrefix; ?>HogeschoolRotterdam.png">
            <img alt="Erasmus Universiteit" src="<?php echo $imgPrefix; ?>EUR.png">
            <img alt="Techniek College Rotterdam" src="<?php echo $imgPrefix; ?>TechniekCollegeRotterdam.png">
        </div>
        <div class="footer-address">
            <strong>Sociaal AI Lab Rotterdam</strong>
            <a href="https://www.google.com/maps/search/?api=1&amp;query=Hillevliet%2090%2C%203074%20KD%20Rotterdam"
               target="_blank"
               rel="noopener noreferrer">
                Hillevliet 90, 3074 KD Rotterdam
            </a>
        </div>
        <p class="copyright">
            &copy; <?php echo date('Y'); ?> Sociaal AI Lab Rotterdam — Samen werken aan inclusieve AI. Alle rechten voorbehouden.
        </p>
    </div>
</footer>