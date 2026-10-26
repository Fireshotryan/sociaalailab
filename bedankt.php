<?php
session_start();
?>
<!doctype html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="modulepreload" as="script" href="build/assets/app-CAiCLEjY.js"><link rel="stylesheet" href="style.css?v=<?php echo filemtime(__DIR__.'/style.css'); ?>"><script type="module" src="build/assets/app-CAiCLEjY.js"></script>
    <title>Bedankt | SociaalAI Lab</title>
    <meta name="description" content="Bedankt voor je bericht aan SociaalAI Lab.">
    <link rel="icon" type="image/png" href="images/Pixels_icon.png">
    <link rel="stylesheet" href="ajax/libs/font-awesome/6.5.0/css/all.min.css">
</head>
<body class="bg-gradient-to-br from-[#00811F] to-[#b9eb34] min-h-screen flex flex-col">

<?php
$navPrefix = '';
include __DIR__ . '/navbar.php';
?>

<main id="main-content" tabindex="-1" class="flex-1">
    <section class="bg-white shadow-lg p-8 max-w-6xl mx-auto my-12">
        <div class="container sociaalai-contact-wrap">
            <h1 class="font-bold text-3xl mb-4 text-[#00811F]">Bedankt voor je bericht!</h1>
            <p class="text-gray-700 leading-relaxed mb-6">We hebben je bericht goed ontvangen en nemen zo spoedig mogelijk contact met je op!</p>
            <div class="flex flex-col md:flex-row gap-3">
                <a href="index.php" class="sociaalai-submit-btn text-center">Terug naar voorpagina</a>
                <a href="contact.php" class="sociaalai-submit-btn text-center" style="background:#ffffff;color:#00811F;border-color:#00811F;">Nog een bericht sturen</a>
            </div>
        </div>
    </section>
</main>

<?php include __DIR__ . '/footer.php'; ?>

</body>
</html>
