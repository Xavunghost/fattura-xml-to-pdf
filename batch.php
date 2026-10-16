<?php

require __DIR__ . '/vendor/autoload.php';

use FatturaXmlToPdf\FatturaXmlToPdf;

$inputDir  = __DIR__ . DIRECTORY_SEPARATOR . 'input';
$outputDir = __DIR__ . DIRECTORY_SEPARATOR . 'output';

$files = glob($inputDir . DIRECTORY_SEPARATOR . '*.xml');

if (empty($files)) {
    echo "Nessun file XML trovato in input/\n";
    exit(0);
}

$converter = new FatturaXmlToPdf();
$ok = 0;
$errors = 0;

foreach ($files as $xmlPath) {
    $pdfName   = pathinfo($xmlPath, PATHINFO_FILENAME) . '.pdf';
    $outputPath = $outputDir . DIRECTORY_SEPARATOR . $pdfName;

    try {
        $converter->convert($xmlPath, $outputPath);
        echo "[OK]    $pdfName\n";
        $ok++;
    } catch (\Throwable $e) {
        echo "[ERRORE] " . basename($xmlPath) . ": " . $e->getMessage() . "\n";
        $errors++;
    }
}

echo "\nCompletato: $ok PDF generati" . ($errors ? ", $errors errori" : '') . "\n";
