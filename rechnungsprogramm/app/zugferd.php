<?php
declare(strict_types=1);

/** ZUGFeRD 2.x / Factur-X, Profil EN 16931 (Cross Industry Invoice). */

function zf_x(string $s): string { return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function zf_amt(int $cents): string { return number_format($cents / 100, 2, '.', ''); }
function zf_date(string $iso): string { return str_replace('-', '', $iso); }

function zf_unit(string $u): string {
    $m = ['std' => 'HUR', 'h' => 'HUR', 'stunde' => 'HUR', 'stunden' => 'HUR', 'm' => 'MTR', 'lfm' => 'MTR', 'm²' => 'MTK', 'qm' => 'MTK', 'm2' => 'MTK',
          'm³' => 'MTQ', 'm3' => 'MTQ', 'kg' => 'KGM', 'km' => 'KMT', 'l' => 'LTR', 'tag' => 'DAY', 'tage' => 'DAY', 'pauschal' => 'LS', 'psch' => 'LS'];
    return $m[rtrim(mb_strtolower(trim($u)), '.')] ?? 'C62';
}

function zugferd_xml(array $inv, array $items, array $customer): string
{
    $small = (bool)$inv['small_business'];
    $cat = fn(float $rate): string => $small ? 'E' : ($rate > 0 ? 'S' : 'Z');
    $party = function (string $name, string $street, string $zip, string $city, string $contact = '', string $legal = '') {
        return '<ram:Name>' . zf_x($name) . "</ram:Name>" . $legal . $contact . "\n<ram:PostalTradeAddress>"
            . ($zip !== '' ? '<ram:PostcodeCode>' . zf_x($zip) . '</ram:PostcodeCode>' : '')
            . ($street !== '' ? '<ram:LineOne>' . zf_x($street) . '</ram:LineOne>' : '')
            . ($city !== '' ? '<ram:CityName>' . zf_x($city) . '</ram:CityName>' : '')
            . '<ram:CountryID>DE</ram:CountryID></ram:PostalTradeAddress>';
    };
    $lines = '';
    foreach ($items as $i => $it) {
        $rate = (float)$it['vat_rate'];
        $lines .= '<ram:IncludedSupplyChainTradeLineItem><ram:AssociatedDocumentLineDocument><ram:LineID>' . ($i + 1) . '</ram:LineID></ram:AssociatedDocumentLineDocument>'
            . '<ram:SpecifiedTradeProduct><ram:Name>' . zf_x(mb_substr(preg_replace('/\s+/', ' ', $it['description']), 0, 200)) . '</ram:Name></ram:SpecifiedTradeProduct>'
            . '<ram:SpecifiedLineTradeAgreement><ram:NetPriceProductTradePrice><ram:ChargeAmount>' . zf_amt((int)$it['unit_price']) . '</ram:ChargeAmount></ram:NetPriceProductTradePrice></ram:SpecifiedLineTradeAgreement>'
            . '<ram:SpecifiedLineTradeDelivery><ram:BilledQuantity unitCode="' . zf_unit($it['unit']) . '">' . rtrim(rtrim(number_format((float)$it['quantity'], 4, '.', ''), '0'), '.') . '</ram:BilledQuantity></ram:SpecifiedLineTradeDelivery>'
            . '<ram:SpecifiedLineTradeSettlement><ram:ApplicableTradeTax><ram:TypeCode>VAT</ram:TypeCode><ram:CategoryCode>' . $cat($rate) . '</ram:CategoryCode>'
            . '<ram:RateApplicablePercent>' . number_format($small ? 0 : $rate, 2, '.', '') . '</ram:RateApplicablePercent>'
            . '</ram:ApplicableTradeTax><ram:SpecifiedTradeSettlementLineMonetarySummation><ram:LineTotalAmount>' . zf_amt((int)$it['total']) . '</ram:LineTotalAmount></ram:SpecifiedTradeSettlementLineMonetarySummation></ram:SpecifiedLineTradeSettlement>'
            . "</ram:IncludedSupplyChainTradeLineItem>\n";
    }
    $calc = calc_invoice(array_map(fn($i) => ['quantity' => (float)$i['quantity'], 'unit_price' => (int)$i['unit_price'], 'vat_rate' => (float)$i['vat_rate']], $items));
    $taxes = '';
    foreach ($calc['net_by_rate'] as $rate => $basis) {
        $r = (float)$rate; $c = $cat($r);
        $taxes .= '<ram:ApplicableTradeTax><ram:CalculatedAmount>' . zf_amt($calc['vat_by_rate'][$rate]) . '</ram:CalculatedAmount><ram:TypeCode>VAT</ram:TypeCode>'
            . ($c === 'E' ? '<ram:ExemptionReason>Kein Ausweis von Umsatzsteuer gemäß § 19 UStG</ram:ExemptionReason>' : '')
            . '<ram:BasisAmount>' . zf_amt($basis) . '</ram:BasisAmount><ram:CategoryCode>' . $c . '</ram:CategoryCode>'
            . '<ram:RateApplicablePercent>' . number_format($small ? 0 : $r, 2, '.', '') . '</ram:RateApplicablePercent>'
            . "</ram:ApplicableTradeTax>\n";
    }
    $taxReg = '';
    if (setting('vat_id') !== '') $taxReg .= '<ram:SpecifiedTaxRegistration><ram:ID schemeID="VA">' . zf_x(setting('vat_id')) . '</ram:ID></ram:SpecifiedTaxRegistration>';
    if (setting('tax_number') !== '') $taxReg .= '<ram:SpecifiedTaxRegistration><ram:ID schemeID="FC">' . zf_x(setting('tax_number')) . '</ram:ID></ram:SpecifiedTaxRegistration>';
    $contact = (setting('owner') !== '' || setting('phone') !== '' || setting('email') !== '')
        ? '<ram:DefinedTradeContact><ram:PersonName>' . zf_x(setting('owner') ?: setting('company')) . '</ram:PersonName>'
          . (setting('phone') !== '' ? '<ram:TelephoneUniversalCommunication><ram:CompleteNumber>' . zf_x(setting('phone')) . '</ram:CompleteNumber></ram:TelephoneUniversalCommunication>' : '')
          . (setting('email') !== '' ? '<ram:EmailURIUniversalCommunication><ram:URIID>' . zf_x(setting('email')) . '</ram:URIID></ram:EmailURIUniversalCommunication>' : '')
          . '</ram:DefinedTradeContact>' : '';
    // BR-CO-26: Verkäufer muss identifizierbar sein (USt-IdNr. oder ersatzweise Steuernummer als Kennung)
    $legalId = setting('vat_id') === '' ? setting('tax_number') : '';
    $legal = $legalId !== '' ? '<ram:SpecifiedLegalOrganization><ram:ID>' . zf_x($legalId) . '</ram:ID></ram:SpecifiedLegalOrganization>' : '';
    $sellerEmail = setting('email') !== '' ? '<ram:URIUniversalCommunication><ram:URIID schemeID="EM">' . zf_x(setting('email')) . '</ram:URIID></ram:URIUniversalCommunication>' : '';
    $buyerEmail = ($customer['email'] ?? '') !== '' ? '<ram:URIUniversalCommunication><ram:URIID schemeID="EM">' . zf_x($customer['email']) . '</ram:URIID></ram:URIUniversalCommunication>' : '';
    $buyerName = customer_name($customer);
    $iban = preg_replace('/\s+/', '', setting('iban'));
    $payment = $iban !== '' ? '<ram:SpecifiedTradeSettlementPaymentMeans><ram:TypeCode>58</ram:TypeCode><ram:PayeePartyCreditorFinancialAccount><ram:IBANID>' . zf_x($iban) . '</ram:IBANID></ram:PayeePartyCreditorFinancialAccount>'
        . (setting('bic') !== '' ? '<ram:PayeeSpecifiedCreditorFinancialInstitution><ram:BICID>' . zf_x(setting('bic')) . '</ram:BICID></ram:PayeeSpecifiedCreditorFinancialInstitution>' : '')
        . '</ram:SpecifiedTradeSettlementPaymentMeans>' : '<ram:SpecifiedTradeSettlementPaymentMeans><ram:TypeCode>1</ram:TypeCode></ram:SpecifiedTradeSettlementPaymentMeans>';
    $due = (int)$inv['gross_amount'];

    return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
    . '<rsm:CrossIndustryInvoice xmlns:rsm="urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100" xmlns:ram="urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100" xmlns:udt="urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100">' . "\n"
    . '<rsm:ExchangedDocumentContext><ram:GuidelineSpecifiedDocumentContextParameter><ram:ID>urn:cen.eu:en16931:2017</ram:ID></ram:GuidelineSpecifiedDocumentContextParameter></rsm:ExchangedDocumentContext>' . "\n"
    . '<rsm:ExchangedDocument><ram:ID>' . zf_x($inv['invoice_number']) . '</ram:ID><ram:TypeCode>380</ram:TypeCode><ram:IssueDateTime><udt:DateTimeString format="102">' . zf_date($inv['invoice_date']) . '</udt:DateTimeString></ram:IssueDateTime>'
    . ($inv['notes'] !== '' ? '<ram:IncludedNote><ram:Content>' . zf_x($inv['notes']) . '</ram:Content></ram:IncludedNote>' : '') . "</rsm:ExchangedDocument>\n"
    . "<rsm:SupplyChainTradeTransaction>\n" . $lines
    . '<ram:ApplicableHeaderTradeAgreement><ram:BuyerReference>' . zf_x($inv['subject'] !== '' ? mb_substr($inv['subject'], 0, 100) : (string)$inv['customer_id']) . '</ram:BuyerReference>'
    . '<ram:SellerTradeParty>' . $party(setting('company') ?: setting('owner'), setting('street'), setting('zip'), setting('city'), $contact, $legal) . $sellerEmail . $taxReg . '</ram:SellerTradeParty>'
    . '<ram:BuyerTradeParty>' . $party($buyerName, $customer['street'], $customer['zip'], $customer['city']) . $buyerEmail . '</ram:BuyerTradeParty></ram:ApplicableHeaderTradeAgreement>' . "\n"
    . '<ram:ApplicableHeaderTradeDelivery><ram:ActualDeliverySupplyChainEvent><ram:OccurrenceDateTime><udt:DateTimeString format="102">' . zf_date($inv['invoice_date']) . '</udt:DateTimeString></ram:OccurrenceDateTime></ram:ActualDeliverySupplyChainEvent></ram:ApplicableHeaderTradeDelivery>' . "\n"
    . '<ram:ApplicableHeaderTradeSettlement><ram:PaymentReference>' . zf_x($inv['invoice_number']) . '</ram:PaymentReference><ram:InvoiceCurrencyCode>EUR</ram:InvoiceCurrencyCode>'
    . $payment . "\n" . $taxes
    . '<ram:SpecifiedTradePaymentTerms><ram:DueDateDateTime><udt:DateTimeString format="102">' . zf_date($inv['due_date']) . '</udt:DateTimeString></ram:DueDateDateTime></ram:SpecifiedTradePaymentTerms>' . "\n"
    . '<ram:SpecifiedTradeSettlementHeaderMonetarySummation><ram:LineTotalAmount>' . zf_amt((int)$inv['net_amount']) . '</ram:LineTotalAmount><ram:TaxBasisTotalAmount>' . zf_amt((int)$inv['net_amount']) . '</ram:TaxBasisTotalAmount>'
    . '<ram:TaxTotalAmount currencyID="EUR">' . zf_amt((int)$inv['vat_amount']) . '</ram:TaxTotalAmount><ram:GrandTotalAmount>' . zf_amt((int)$inv['gross_amount']) . '</ram:GrandTotalAmount><ram:DuePayableAmount>' . zf_amt($due) . '</ram:DuePayableAmount></ram:SpecifiedTradeSettlementHeaderMonetarySummation>'
    . "</ram:ApplicableHeaderTradeSettlement>\n</rsm:SupplyChainTradeTransaction>\n</rsm:CrossIndustryInvoice>\n";
}

function zugferd_xmp(string $title): string
{
    $now = date('Y-m-d\TH:i:sP');
    $t = zf_x($title);
    return '<?xpacket begin="' . "\xEF\xBB\xBF" . '" id="W5M0MpCehiHzreSzNTczkc9d"?>' . "\n"
    . '<x:xmpmeta xmlns:x="adobe:ns:meta/"><rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">'
    . '<rdf:Description rdf:about="" xmlns:pdfaid="http://www.aiim.org/pdfa/ns/id/"><pdfaid:part>3</pdfaid:part><pdfaid:conformance>B</pdfaid:conformance></rdf:Description>'
    . '<rdf:Description rdf:about="" xmlns:dc="http://purl.org/dc/elements/1.1/"><dc:title><rdf:Alt><rdf:li xml:lang="x-default">' . $t . '</rdf:li></rdf:Alt></dc:title></rdf:Description>'
    . '<rdf:Description rdf:about="" xmlns:pdf="http://ns.adobe.com/pdf/1.3/"><pdf:Producer>Rechnungsprogramm</pdf:Producer></rdf:Description>'
    . '<rdf:Description rdf:about="" xmlns:xmp="http://ns.adobe.com/xap/1.0/"><xmp:CreateDate>' . $now . '</xmp:CreateDate><xmp:ModifyDate>' . $now . '</xmp:ModifyDate><xmp:CreatorTool>Rechnungsprogramm</xmp:CreatorTool></rdf:Description>'
    . '<rdf:Description rdf:about="" xmlns:pdfaExtension="http://www.aiim.org/pdfa/ns/extension/" xmlns:pdfaSchema="http://www.aiim.org/pdfa/ns/schema#" xmlns:pdfaProperty="http://www.aiim.org/pdfa/ns/property#"><pdfaExtension:schemas><rdf:Bag><rdf:li rdf:parseType="Resource">'
    . '<pdfaSchema:schema>Factur-X PDFA Extension Schema</pdfaSchema:schema><pdfaSchema:namespaceURI>urn:factur-x:pdfa:CrossIndustryDocument:invoice:1p0#</pdfaSchema:namespaceURI><pdfaSchema:prefix>fx</pdfaSchema:prefix><pdfaSchema:property><rdf:Seq>'
    . '<rdf:li rdf:parseType="Resource"><pdfaProperty:name>DocumentFileName</pdfaProperty:name><pdfaProperty:valueType>Text</pdfaProperty:valueType><pdfaProperty:category>external</pdfaProperty:category><pdfaProperty:description>Name of the embedded XML invoice file</pdfaProperty:description></rdf:li>'
    . '<rdf:li rdf:parseType="Resource"><pdfaProperty:name>DocumentType</pdfaProperty:name><pdfaProperty:valueType>Text</pdfaProperty:valueType><pdfaProperty:category>external</pdfaProperty:category><pdfaProperty:description>INVOICE</pdfaProperty:description></rdf:li>'
    . '<rdf:li rdf:parseType="Resource"><pdfaProperty:name>Version</pdfaProperty:name><pdfaProperty:valueType>Text</pdfaProperty:valueType><pdfaProperty:category>external</pdfaProperty:category><pdfaProperty:description>The actual version of the Factur-X XML schema</pdfaProperty:description></rdf:li>'
    . '<rdf:li rdf:parseType="Resource"><pdfaProperty:name>ConformanceLevel</pdfaProperty:name><pdfaProperty:valueType>Text</pdfaProperty:valueType><pdfaProperty:category>external</pdfaProperty:category><pdfaProperty:description>The conformance level of the embedded Factur-X data</pdfaProperty:description></rdf:li>'
    . '</rdf:Seq></pdfaSchema:property></rdf:li></rdf:Bag></pdfaExtension:schemas></rdf:Description>'
    . '<rdf:Description rdf:about="" xmlns:fx="urn:factur-x:pdfa:CrossIndustryDocument:invoice:1p0#"><fx:DocumentType>INVOICE</fx:DocumentType><fx:DocumentFileName>factur-x.xml</fx:DocumentFileName><fx:Version>1.0</fx:Version><fx:ConformanceLevel>EN 16931</fx:ConformanceLevel></rdf:Description>'
    . '</rdf:RDF></x:xmpmeta>' . "\n" . '<?xpacket end="w"?>';
}
