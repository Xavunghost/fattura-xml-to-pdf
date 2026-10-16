<?php

namespace FatturaXmlToPdf;

use TCPDF;

class FatturaXmlToPdf
{
    public function convert(string $xmlPath, string $outputPath): void
    {
        if (! file_exists($xmlPath)) {
            throw new \RuntimeException("XML non trovato: $xmlPath");
        }
        $xml = simplexml_load_file($xmlPath);
        if ($xml === false) {
            throw new \RuntimeException("XML non valido: $xmlPath");
        }
        $this->drawPdf($this->parse($xml), $outputPath);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PARSING
    // ─────────────────────────────────────────────────────────────────────────

    private function parse(\SimpleXMLElement $xml): array
    {
        $supplier = $xml->FatturaElettronicaHeader->CedentePrestatore;
        $customer = $xml->FatturaElettronicaHeader->CessionarioCommittente;
        $doc      = $xml->FatturaElettronicaBody->DatiGenerali->DatiGeneraliDocumento;
        $payment  = $xml->FatturaElettronicaBody->DatiPagamento->DettaglioPagamento ?? null;

        $items = [];
        foreach ($xml->FatturaElettronicaBody->DatiBeniServizi->DettaglioLinee as $line) {
            $items[] = [
                'descrizione'     => (string) $line->Descrizione,
                'um'              => (string) $line->UnitaMisura,
                'quantita'        => isset($line->Quantita) ? (float) $line->Quantita : null,
                'prezzo_unitario' => isset($line->PrezzoUnitario) ? (float) $line->PrezzoUnitario : null,
                'importo'         => isset($line->PrezzoTotale) ? (float) $line->PrezzoTotale : null,
                'iva'             => isset($line->AliquotaIVA) ? (float) $line->AliquotaIVA : null,
            ];
        }

        $vat_summary = [];
        foreach ($xml->FatturaElettronicaBody->DatiBeniServizi->DatiRiepilogo as $vat) {
            $vat_summary[] = [
                'esigibilita' => (string) $vat->EsigibilitaIVA ?: 'I',
                'aliquota'    => (float) $vat->AliquotaIVA,
                'imponibile'  => (float) $vat->ImponibileImporto,
                'imposta'     => (float) $vat->Imposta,
            ];
        }

        $total_net = array_sum(array_column($vat_summary, 'imponibile'));
        $total_vat = array_sum(array_column($vat_summary, 'imposta'));
        $total     = $total_net + $total_vat;

        $rawDate       = (string) $doc->Data;
        $formattedDate = $this->formatDate($rawDate);
        $year          = substr($rawDate, 0, 4);

        $docNumber    = (string) $doc->Numero;
        $titleNumber  = str_contains($docNumber, '/') ? $docNumber : $docNumber . '/' . $year;

        $paymentCode   = $payment ? (string) $payment->ModalitaPagamento : '';
        $paymentIban   = $payment ? trim((string) $payment->IBAN) : '';
        $paymentAmount = $payment && (float) $payment->ImportoPagamento > 0
            ? (float) $payment->ImportoPagamento
            : $total;
        $paymentDueRaw = $payment ? (string) $payment->DataScadenzaPagamento : '';
        $paymentDue    = $paymentDueRaw ? $this->formatDate($paymentDueRaw) : $formattedDate;

        return [
            // Fornitore
            'supplier_name'    => trim((string) $supplier->DatiAnagrafici->Anagrafica->Denominazione),
            'supplier_vat'     => (string) $supplier->DatiAnagrafici->IdFiscaleIVA->IdCodice,
            'supplier_cf'      => (string) $supplier->DatiAnagrafici->CodiceFiscale,
            'supplier_address' => $this->buildAddress($supplier->Sede),
            'supplier_city'    => $this->buildCity($supplier->Sede),
            'supplier_email'   => trim((string) $supplier->Contatti->Email),

            // Cliente
            'customer_name'    => trim((string) $customer->DatiAnagrafici->Anagrafica->Denominazione),
            'customer_vat'     => (string) $customer->DatiAnagrafici->IdFiscaleIVA->IdCodice,
            'customer_cf'      => (string) $customer->DatiAnagrafici->CodiceFiscale,
            'customer_address' => $this->buildAddress($customer->Sede),
            'customer_city'    => $this->buildCity($customer->Sede),

            // Documento
            'document_number'  => $docNumber,
            'title_number'     => $titleNumber,
            'document_date'    => $formattedDate,
            'document_year'    => $year,

            // Righe e IVA
            'items'            => $items,
            'vat_summary'      => $vat_summary,
            'total_net'        => $total_net,
            'total_vat'        => $total_vat,
            'total'            => $total,

            // Pagamento
            'payment_code'     => $paymentCode,
            'payment_label'    => $this->paymentLabel($paymentCode),
            'payment_iban'     => $paymentIban,
            'payment_amount'   => $paymentAmount,
            'payment_due'      => $paymentDue,
        ];
    }

    private function buildAddress(\SimpleXMLElement $sede): string
    {
        $parts = array_filter([
            trim((string) $sede->Indirizzo),
            trim((string) $sede->NumeroCivico),
        ]);
        return implode(' ', $parts);
    }

    private function buildCity(\SimpleXMLElement $sede): string
    {
        $cap      = trim((string) $sede->CAP);
        $comune   = strtoupper(trim((string) $sede->Comune));
        $provincia = trim((string) $sede->Provincia);
        return trim(implode(' ', array_filter([
            $cap,
            $comune,
            $provincia ? "($provincia)" : '',
        ])));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // RENDERING PDF
    // ─────────────────────────────────────────────────────────────────────────

    private function drawPdf(array $d, string $outputPath): void
    {
        $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('FatturaXmlToPdf');
        $pdf->SetMargins(15, 15, 15);
        $pdf->SetAutoPageBreak(false, 0);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->AddPage();

        $lm = 15;   // left margin
        $W  = 180;  // content width
        $gray = [180, 180, 180];

        // ── 1. FORNITORE (top right) ──────────────────────────────────────────
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->SetXY($lm, 15);
        $pdf->MultiCell($W, 5, $d['supplier_name'], 0, 'R', false, 1);

        $supplierLine2 = trim($d['supplier_address']
            . ($d['supplier_city'] ? ' - ' . $d['supplier_city'] : ''));
        if ($supplierLine2) {
            $pdf->SetFont('helvetica', '', 9);
            $pdf->SetX($lm);
            $pdf->MultiCell($W, 4, $supplierLine2, 0, 'R', false, 1);
        }

        $vatCf = implode(' - ', array_filter([
            $d['supplier_vat'] ? 'P.iva ' . $d['supplier_vat'] : '',
            $d['supplier_cf'] ? 'C.F. ' . $d['supplier_cf'] : '',
        ]));
        if ($vatCf) {
            $pdf->SetFont('helvetica', '', 9);
            $pdf->SetX($lm);
            $pdf->MultiCell($W, 4, $vatCf, 0, 'R', false, 1);
        }

        // ── 2. TITOLO FATTURA ─────────────────────────────────────────────────
        $yTitle = $pdf->GetY() + 6;
        $pdf->SetFont('helvetica', 'B', 15);
        $pdf->SetXY($lm, $yTitle);
        $pdf->Cell($W, 9, 'FATTURA nr. ' . $d['title_number'] . ' del ' . $d['document_date'], 0, 1, 'L');

        // ── SEPARATOR ─────────────────────────────────────────────────────────
        $yLine1 = $pdf->GetY() + 1;
        $pdf->SetDrawColor(...$gray);
        $pdf->Line($lm, $yLine1, $lm + $W, $yLine1);
        $pdf->SetDrawColor(0, 0, 0);

        // ── 3. SEZIONE CLIENTE (due colonne) ─────────────────────────────────
        $yCustomer = $yLine1 + 4;
        $leftColW  = 60;
        $rightColW = $W - $leftColW;
        $rightX    = $lm + $leftColW;

        // Colonna sinistra: P.IVA / CF del cliente
        $leftY = $yCustomer;
        $pdf->SetFont('helvetica', '', 8);
        if ($d['customer_vat']) {
            $pdf->SetXY($lm, $leftY);
            $pdf->MultiCell($leftColW, 5, 'P.IVA ' . $d['customer_vat'], 0, 'L', false, 1);
            $leftY += 5;
        }
        if ($d['customer_cf'] && $d['customer_cf'] !== $d['customer_vat']) {
            $pdf->SetXY($lm, $leftY);
            $pdf->MultiCell($leftColW, 5, 'CF ' . $d['customer_cf'], 0, 'L', false, 1);
            $leftY += 5;
        }

        // Colonna destra: DESTINATARIO
        $pdf->SetFont('helvetica', '', 7);
        $pdf->SetXY($rightX, $yCustomer);
        $pdf->Cell($rightColW, 4, 'DESTINATARIO', 0, 1, 'L');
        $pdf->SetFont('helvetica', 'B', 11);
        $pdf->SetX($rightX);
        $pdf->MultiCell($rightColW, 6, $d['customer_name'], 0, 'L', false, 1);
        $pdf->SetFont('helvetica', '', 9);
        if ($d['customer_address']) {
            $pdf->SetX($rightX);
            $pdf->MultiCell($rightColW, 4.5, $d['customer_address'], 0, 'L', false, 1);
        }
        if ($d['customer_city']) {
            $pdf->SetX($rightX);
            $pdf->MultiCell($rightColW, 4.5, $d['customer_city'], 0, 'L', false, 1);
        }

        $yAfterCustomer = max($leftY, $pdf->GetY()) + 4;

        // ── SEPARATOR ─────────────────────────────────────────────────────────
        $pdf->SetDrawColor(...$gray);
        $pdf->Line($lm, $yAfterCustomer, $lm + $W, $yAfterCustomer);
        $pdf->SetDrawColor(0, 0, 0);

        // ── 4. TABELLA ARTICOLI ───────────────────────────────────────────────
        $yItems  = $yAfterCustomer + 4;
        $descW   = 148;
        $importoW = 32;

        // Intestazione
        $pdf->SetFont('helvetica', 'B', 8);
        $pdf->SetFillColor(245, 245, 245);
        $pdf->SetXY($lm, $yItems);
        $pdf->Cell($descW, 6, 'DESCRIZIONE', 'B', 0, 'L', true);
        $pdf->Cell($importoW, 6, 'IMPORTO', 'B', 1, 'R', true);
        $pdf->SetFillColor(255, 255, 255);

        // Righe
        $pdf->SetFont('helvetica', '', 9);
        foreach ($d['items'] as $item) {
            $pdf->SetX($lm);
            $rowH = max(7, $pdf->getStringHeight($descW, $item['descrizione']) + 1);
            $pdf->MultiCell($descW, $rowH, $item['descrizione'], 0, 'L', false, 0);
            $importoText = $item['importo'] !== null
                ? '€ ' . number_format($item['importo'], 2, ',', '.')
                : '';
            $pdf->MultiCell($importoW, $rowH, $importoText, 0, 'R', false, 1);
        }

        // ── 5. NOTE ───────────────────────────────────────────────────────────
        $yNote = $pdf->GetY() + 5;
        $pdf->SetFont('helvetica', '', 7);
        $pdf->SetXY($lm, $yNote);
        $pdf->Cell($W, 4, 'NOTE', 0, 1, 'L');
        $pdf->SetFont('helvetica', '', 8);
        $pdf->SetX($lm);
        $pdf->MultiCell($W, 4,
            "Documento privo di valenza fiscale ai sensi dell'art. 21 Dpr 633/72. "
            . "L'originale è disponibile all'indirizzo telematico da Lei fornito "
            . "oppure nella Sua area riservata dell'Agenzia delle Entrate.",
            0, 'L', false, 1);

        // ── 6. SEZIONE INFERIORE (bottom of page) ────────────────────────────
        //    Altezza stimata della sezione:
        //    separator + pagamento(22) + separator + iva(35) + separator + footer(7) ≈ 66mm
        $bottomSectionH = 66;
        $yBottom        = 297 - 15 - $bottomSectionH;
        $halfW          = $W / 2;
        $xCenter        = $lm + $halfW;

        // ── Separator superiore ───────────────────────────────────────────────
        $pdf->SetDrawColor(...$gray);
        $pdf->Line($lm, $yBottom, $lm + $W, $yBottom);

        // ── PAGAMENTO | SCADENZE ──────────────────────────────────────────────
        $yPay = $yBottom + 3;

        // Sinistra: modalità pagamento + IBAN
        $pdf->SetFont('helvetica', '', 7);
        $pdf->SetXY($lm, $yPay);
        $pdf->Cell($halfW, 4, 'MODALITÀ DI PAGAMENTO', 0, 1, 'L');
        $pdf->SetX($lm);
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->Cell($halfW, 5, $d['payment_label'] ?: $d['payment_code'], 0, 1, 'L');
        if ($d['payment_iban']) {
            $pdf->SetX($lm);
            $pdf->SetFont('helvetica', '', 7);
            $pdf->Cell(8, 5, 'IBAN:', 0, 0, 'L');
            $pdf->SetFont('helvetica', 'B', 8);
            $pdf->Cell($halfW - 8, 5, $d['payment_iban'], 0, 1, 'L');
        }

        // Linea verticale centrale
        $pdf->Line($xCenter, $yBottom + 1, $xCenter, $yBottom + 23);

        // Destra: scadenze
        $pdf->SetFont('helvetica', '', 7);
        $pdf->SetXY($xCenter + 3, $yPay);
        $pdf->Cell($halfW - 3, 4, 'SCADENZE', 0, 1, 'L');
        $pdf->SetX($xCenter + 3);
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->Cell($halfW - 3, 5, $d['payment_due'] . ': € ' . number_format($d['payment_amount'], 2, ',', '.'), 0, 1, 'L');

        // ── Separator IVA ─────────────────────────────────────────────────────
        $yIvaSep = $yBottom + 25;
        $pdf->Line($lm, $yIvaSep, $lm + $W, $yIvaSep);

        // ── RIEPILOGO IVA | TOTALI ────────────────────────────────────────────
        $yIva     = $yIvaSep + 3;
        $ivaColW  = $halfW - 5;
        $col3     = $ivaColW / 3;

        // Intestazione IVA
        $pdf->SetFont('helvetica', '', 7);
        $pdf->SetXY($lm, $yIva);
        $pdf->Cell($col3, 4, 'RIEPILOGO IVA', 0, 0, 'L');
        $pdf->Cell($col3, 4, 'IMPONIBILE', 0, 0, 'R');
        $pdf->Cell($col3, 4, 'IMPOSTE', 0, 1, 'R');

        // Righe IVA
        $pdf->SetFont('helvetica', '', 8);
        foreach ($d['vat_summary'] as $vat) {
            $pdf->SetX($lm);
            $pdf->Cell($col3, 4.5, number_format($vat['aliquota'], 0) . '%', 0, 0, 'L');
            $pdf->Cell($col3, 4.5, number_format($vat['imponibile'], 2, ',', '.'), 0, 0, 'R');
            $pdf->Cell($col3, 4.5, '€ ' . number_format($vat['imposta'], 2, ',', '.'), 0, 1, 'R');
        }

        // Linea verticale centrale sezione IVA
        $pdf->Line($xCenter, $yIvaSep + 1, $xCenter, $yIvaSep + 38);

        // Totali a destra
        $totColW = $halfW + 5;
        $pdf->SetFont('helvetica', '', 8);
        $pdf->SetXY($xCenter + 3, $yIva + 1);
        $pdf->Cell($totColW - 28, 4.5, 'Imponibile', 0, 0, 'L');
        $pdf->Cell(25, 4.5, '€ ' . number_format($d['total_net'], 2, ',', '.'), 0, 1, 'R');
        $pdf->SetX($xCenter + 3);
        $pdf->Cell($totColW - 28, 4.5, 'Totale IVA', 0, 0, 'L');
        $pdf->Cell(25, 4.5, '€ ' . number_format($d['total_vat'], 2, ',', '.'), 0, 1, 'R');

        // Totale grande
        $pdf->SetFont('helvetica', 'B', 22);
        $pdf->SetXY($xCenter + 3, $pdf->GetY() + 3);
        $pdf->Cell($totColW - 3, 11,
            '€ ' . number_format($d['total'], 2, ',', '.'),
            0, 1, 'R');

        // ── Separator finale ──────────────────────────────────────────────────
        $yFinalLine = $yIvaSep + 38;
        $pdf->Line($lm, $yFinalLine, $lm + $W, $yFinalLine);

        // ── 7. FOOTER ─────────────────────────────────────────────────────────
        $pdf->SetFont('helvetica', '', 7);
        $pdf->SetXY($lm, $yFinalLine + 2);
        $footerLeft  = 'Fattura nr. ' . $d['title_number'] . ' del ' . $d['document_date'] . ' - 1 / 1';
        $footerRight = $d['supplier_name'] . ($d['supplier_email'] ? '   ' . $d['supplier_email'] : '');
        $pdf->Cell($halfW, 4, $footerLeft, 0, 0, 'L');
        $pdf->Cell($halfW, 4, $footerRight, 0, 1, 'R');

        $pdf->Output($outputPath, 'F');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // HELPERS
    // ─────────────────────────────────────────────────────────────────────────

    private function formatDate(string $raw): string
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $raw, $m)) {
            return $m[3] . '/' . $m[2] . '/' . $m[1];
        }
        return $raw;
    }

    private function paymentLabel(string $code): string
    {
        return [
            'MP01' => 'CONTANTI',
            'MP02' => 'ASSEGNO',
            'MP03' => 'ASSEGNO CIRCOLARE',
            'MP05' => 'BONIFICO',
            'MP06' => 'VAGLIA CAMBIARIO',
            'MP07' => 'BOLLETTINO BANCARIO',
            'MP08' => 'CARTA DI CREDITO',
            'MP09' => 'RID',
            'MP12' => 'RIBA',
            'MP13' => 'MAV',
            'MP16' => 'DOMICILIAZIONE BANCARIA',
            'MP19' => 'SEPA DIRECT DEBIT',
            'MP23' => 'PagoPA',
        ][$code] ?? $code;
    }
}
