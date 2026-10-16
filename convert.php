<?php

require __DIR__ . '/vendor/autoload.php';

use FatturaXmlToPdf\FatturaXmlToPdf;

$args = array_slice($argv, 1);

if (count($args) < 1) {
    echo "Uso: php convert.php <input.xml> [output.pdf]\n";
    echo "  Se output.pdf non viene specificato, viene usato lo stesso nome dell'XML con estensione .pdf\n";
    exit(1);
}

$inputXml  = realpath($args[0]) ?: $args[0];
$outputPdf = isset($args[1])
    ? (str_contains($args[1], ':') ? $args[1] : realpath(dirname($args[1])) . DIRECTORY_SEPARATOR . basename($args[1]))
    : preg_replace('/\.xml$/i', '', $inputXml) . '.pdf';

try {
    (new FatturaXmlToPdf())->convert($inputXml, $outputPdf);
    echo "PDF generato: $outputPdf\n";
} catch (\Throwable $e) {
    echo "Errore: " . $e->getMessage() . "\n";
    exit(1);
}
