<?php

namespace NFse\Service;

use Exception;
use Mpdf\Mpdf;
use NFse\Helpers\Utils;
use NFse\Models\NFse;

class PrintPDFNFse
{
    private $html;
    private $nfse;
    private $logo64;

    private $operations = [
        1 => 'Tributação no município',
        2 => 'Tributação fora do município',
        3 => 'Isenção',
        4 => 'Imune',
        5 => 'Exigibilidade suspensa por decisão judicial',
        6 => 'Exigibilidade suspensa por procedimento administrativo',
    ];

    private $regimes = [
        1 => 'Microempresa municipal',
        2 => 'Estimativa',
        3 => 'Sociedade de profissionais',
        4 => 'Cooperativa',
        5 => 'MEI – Simples Nacional',
        6 => 'ME ou EPP do Simples Nacional',
    ];

    /**
     *recebe o objeto da nota fiscal para impressão.
     *
     * @param NFse\Models\NFse;
     */
    public function __construct(NFse $nfse, string $logo64)
    {
        $this->nfse = $nfse;
        $this->logo64 = $logo64;
    }

    /**
     * Generates and returns a PDF or HTML representation of the NFS-e.
     *
     * @param string $outputType The type of output desired. 'I' for HTML (inline view),
     *                           'D' for direct download as PDF.
     * @return string The generated content, either as an HTML string or a PDF binary string,
     *                based on the specified output type.
     * @throws \InvalidArgumentException If the output type is invalid.
     */
    public function getPDF(string $outputType): string
    {
        $this->validateOutputType($outputType);

        $html = $this->generateHtml($outputType);

        if ($outputType === 'I') {
            return $html;
        }

        return $this->generatePdf($html, $outputType);
    }

    /**
     * Generates the HTML string for the NFS-e, replacing placeholders and adding
     * cancellation marks if necessary.
     *
     * @param string $outputType The type of output desired. 'I' for HTML (inline view),
     *                           'D' for direct download as PDF.
     * @return string The generated HTML string.
     */
    private function generateHtml(string $outputType): string
    {
        $templatePath = __DIR__ . '/../../storage/cdn/html/print.html';
        $this->html = file_get_contents($templatePath);

        $this->replacePlaceholders($outputType);
        $this->addCancellationMark();

        return $this->html;
    }

    /**
     * Replaces placeholders in the HTML template with actual values.
     *
     * The placeholders are in the format {PLACEHOLDER_NAME} and are replaced
     * with the values from the NFS-e model.
     *
     */
    private function replacePlaceholders(string $outputType): void
    {
        $specialTaxRegime = '';

        if (!empty($this->nfse->service->specialTaxRegime)) {
            $specialTaxRegime = '<div style="margin: 0px 5px;">
            <span id="j_id106"></span>
            <table border="0" cellpadding="4" cellspacing="0" width="100%">
                <tbody>
                    <tr>
                        <td width="33%" height="25" align="left" valign="middle" class="bordaLateral">
                            <p class="teste">
                                <span class="subTitulo">Regime Especial de Tributação:</span>
                                ' . $this->regimes[$this->nfse->service->specialTaxRegime] . '
                            </p>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>';
        }

        $optanteSimplesNacional = '';

        if ($this->nfse->service->simpleNational) {
            $optanteSimplesNacional = '<span id="form:j_id177">
            <tr>
                <td class="bordaInferior" style="padding: 5px;">
                    <span class="subTitulo">Documento emitido por ME ou EPP optante pelo Simples Nacional.</span>
                </td>
            </tr>
        </span>';
        }

        $replacements = [
            // Styles
            '/* {PRINT_CSS}*/' => $this->getCssForOutputType($outputType),

            // Header
            '{ANO}' => $this->nfse->year,
            '{NFSE_NUMERO}' => $this->nfse->number,
            '{DATA_EMISSAO}' => $this->nfse->dateEmission,
            '{HORA_EMISSAO}' => ' às ' . $this->nfse->timeEmission,
            '{COMPETENCIA}' => $this->nfse->competence,
            '{CODIGO_VERIFICACAO}' => $this->nfse->verificationCode,
            '{LOGO_BASE_64}' => $this->logo64,
            '{NFE_SUBSTITUIDA}' => $this->getReplacedNfseMarkup(),

            // Prestador
            '{RAZAO_SOCIAL_PRESTADOR}' => $this->nfse->provider->name,
            '{CPF_CNPJ_PRESTADOR}' => Utils::mask((string) $this->nfse->provider->cnpj, '##.###.###/####-##'),
            '{INSCRICAO_MUNICIPAL_PRESTADOR}' => Utils::mask((string) $this->nfse->provider->inscription, '#######/###-#'),
            '{LOGRADOURO_PRESTADOR}' => $this->nfse->provider->address->address,
            '{NUMERO_ENDERECO_PRESTADOR}' => $this->nfse->provider->address->number,
            '{BAIRRO_PRESTADOR}' => $this->nfse->provider->address->neighborhood,
            '{CEP_PRESTADOR}' => Utils::mask((string) $this->nfse->provider->address->zipCode, '##.###-###'),
            '{MUNICIPIO_PRESTADOR}' => $this->nfse->provider->address->city,
            '{ESTADO_PRESTADOR}' => $this->nfse->provider->address->state,
            '{TELEFONE_PRESTADOR}' => Utils::addPhoneMask($this->nfse->provider->phone),
            '{EMAIL_PRESTADOR}' => $this->nfse->provider->email,

            // Tomador
            '{RAZAO_SOCIAL_TOMADOR}' => $this->nfse->taker->name,
            '{CPF_CNPJ_TOMADOR}' => (strlen($this->nfse->taker->document) > 11) ?
                Utils::mask((string) $this->nfse->taker->document, '##.###.###/####-##') :
                Utils::mask((string) $this->nfse->taker->document, '###.###.###-##'),
            '{INSCRICAO_MUNICIPAL_TOMADOR}' => ($this->nfse->taker->municipalRegistration) ?
                Utils::mask((string) $this->nfse->taker->municipalRegistration, '#######/###-#') : 'Não Informado',
            '{LOGRADOURO_TOMADOR}' => $this->nfse->taker->address,
            '{NUMERO_ENDERECO_TOMADOR}' => $this->nfse->taker->number,
            '{BAIRRO_TOMADOR}' => $this->nfse->taker->neighborhood,
            '{CEP_TOMADOR}' => Utils::mask((string) $this->nfse->taker->zipCode, '##.###-###'),
            '{MUNICIPIO_TOMADOR}' => $this->nfse->taker->city,
            '{ESTADO_TOMADOR}' => $this->nfse->taker->state,
            '{TELEFONE_TOMADOR}' => Utils::addPhoneMask($this->nfse->taker->phone),
            '{EMAIL_TOMADOR}' => $this->nfse->taker->email,

            // Body
            '{DESCRIMINACAO}' => $this->nfse->service->description,
            '{CODIGO_TRIBUTACAO_MUNICIPAL}' => Utils::mask((string) $this->nfse->service->municipalityTaxationCode, '####-#/##-##'),
            '{DESCRICAO_TRIBUTACAO_MUNICIPAL}' => $this->nfse->service->taxCodeDescription,
            '{ITEM_LISTA_SERVICO}' => $this->nfse->service->itemList,
            '{DESCRICAO_LISTA_SERVICO}' => $this->nfse->service->itemDescription,
            '{CODIGO_MUNICIPIO_GERADOR}' => $this->nfse->service->municipalCode,
            '{NOME_MUNICIPIO_GERADOR}' => $this->nfse->service->municipalName,
            '{NATUREZA_OPERACAO}' => $this->operations[$this->nfse->service->nature],
            '{REGIME_ESPECIAL_TRIBUTACAO}' => $specialTaxRegime,

            // Valores
            '{VALOR_SERVICOS}' => Utils::formatRealMoney($this->nfse->service->serviceValue ?? 0),
            '{VALOR_DESCONTO_CONDICIONADO}' => Utils::formatRealMoney($this->nfse->service->discountCondition ?? 0),
            '{TOTAL_RETENCOES_FEDERAIS}' => Utils::formatRealMoney($this->nfse->service->otherWithholdings ?? 0),
            '{VALOR_ISS_RETIDO}' => Utils::formatRealMoney($this->nfse->service->issValueWithheld ?? 0),
            '{VALOR_LIQUIDO}' => Utils::formatRealMoney($this->nfse->service->netValue ?? 0),
            '{DEDUCOES}' => Utils::formatRealMoney($this->nfse->service->valueDeductions ?? 0),
            '{VALOR_DESCONTO_INCONDICIONADO}' => Utils::formatRealMoney($this->nfse->service->unconditionedDiscount ?? 0),
            '{BASE_CALCULO}' => Utils::formatRealMoney($this->nfse->service->calculationBase ?? 0),
            '{ALIQUOTA_SERVICOS}' => $this->nfse->service->aliquot * 100 . ' % ',
            '{VALOR_ISS}' => Utils::formatRealMoney($this->nfse->service->issValue ?? 0),
            '{VALOR_PIS}' => Utils::formatRealMoney($this->nfse->service->valuePis ?? 0),
            '{VALOR_COFINS}' => Utils::formatRealMoney($this->nfse->service->valueConfis ?? 0),
            '{VALOR_IR}' => Utils::formatRealMoney($this->nfse->service->valueIR ?? 0),
            '{VALOR_CSLL}' => Utils::formatRealMoney($this->nfse->service->valueCSLL ?? 0),
            '{VALOR_INSS}' => Utils::formatRealMoney($this->nfse->service->valueINSS ?? 0),
            '{OPTANTE_PELO_SIMPLES}' => $optanteSimplesNacional,
        ];

        $this->html = str_replace(
            array_keys($replacements),
            array_values($replacements),
            $this->html
        );
    }

    /**
     * Generates the markup for the replaced NFS-e section.
     *
     * @return string HTML markup indicating the NFS-e number that has been replaced.
     *                Returns an empty string if no replaced NFS-e number is present.
     */
    private function getReplacedNfseMarkup(): string
    {
        if (empty($this->nfse->nfseNumberReplaced)) {
            return '';
        }

        return '
        <tr>
            <td colspan="2">
                <hr class="linhaDivisao"/>
            </td>
        </tr>
        <tr>
            <td colspan="2">
               <div class="box04">
                    <table>
                        <tbody>
                            <tr>
                                <td colspan="2">
                                    <span>
                                        NFS-e Substituída:' . substr($this->nfse->nfseNumberReplaced, 0, 4) . '/' . substr($this->nfse->nfseNumberReplaced, 4) .
            '</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </td>
        </tr>';
    }

    /**
     * Returns the CSS styles for the NFS-e output based on the specified output type.
     *
     * @param string $outputType The type of output desired. 'I' for print style
     *                           with larger fonts and additional styling, or any
     *                           other value for a more compact print style.
     *
     * @return string A CSS string with styles tailored for the specified output type.
     */
    private function getCssForOutputType(string $outputType): string
    {
        if ($outputType === 'I') {
            return '@media print {
                body {
                    font: 19px "Trebuchet MS", Verdana, Arial;
                    color: #175366;
                    text-align: center;
                }
                .logo {
                    max-width: 230px;
                    padding: 10px;
                }
                .teste {
                    font: 19px "Trebuchet MS", Verdana, Arial;
                    color: #175366;
                }
                .hh1 {
                    font: 25px Verdana, Arial;
                }
                .hh2 {
                    font: bold 19px "Trebuchet MS", Verdana, Arial;
                }
                .hh3 {
                    font: 19px "Trebuchet MS", Verdana, Arial;
                }
                .noprint {
                    display: none;
                }
                .box01, .box02, .box03, .box04, .box05 {
                    background: none;
                }
                h1, h2, h3 {
                    font-size: 19px;
                }
                .numeroDestaque {
                    font-size: 30px;
                }
                .valorLiquido, .issRetido {
                    font-size: 20px;
                    color: #c32b16;
                    padding: 5px 5px 2px;
                }
                .cnpjPrincipal, .subTitulo {
                    font-size: 19px;
                    font-weight: bold;
                }
                .tableTributos {
                    font-size: 19px;
                }
                .tableTributos th {
                    font-size: 19px;
                    background: #eeeeee;
                    text-align: center;
                    padding: 1px 3px;
                }
                .tableTributos td {
                    font-size: 19px;
                    background: #FFFFFF;
                    text-align: right;
                    padding: 1px 3px;
                }
                .dataEmissao {
                    font-size: 19px;
                    font-weight: bold;
                }
                .title {
                    font-size: 25px;
                }
                .linhaDivisao {
                    display: none;
                }
                .servicos {
                    font-size: 19px;
                }
            }';
        }

        return '@media print {
            body {
                font: 10px "Trebuchet MS", Verdana, Arial;
                color: #175366;
                text-align: center;
            }
            .noprint {
                display: none;
            }
            .box01, .box02, .box03, .box04, .box05 {
                background: none;
            }
            .linhaDivisao {
                display: block;
                margin-bottom: -1px;
            }
            .hh2 {
                font: bold 13px "Trebuchet MS", Verdana, Arial;
                color: #175366;
                border-bottom: 1px solid #65A0C0;
                margin: 0px;
            }
            .servicos {
                padding: 0 2px;
                font-size: 9px;
            }
            .subTitulo {
                font-size: 11px;
                font-weight: bold;
            }
        }';
    }

    /**
     * Generates a PDF from the given HTML.
     *
     * @param string $html The HTML to be converted to PDF.
     * @param string $outputType The type of output desired. 'I' for HTML (inline view),
     *                           'D' for direct download as PDF.
     * @return string The generated PDF content as a string.
     * @throws Exception If there is an error generating the PDF.
     */
    private function generatePdf(string $html, string $outputType): string
    {
        try {
            $mpdf = new Mpdf([
                'default_font' => 'chelvetica',
                'margin_top' => 10,
                'margin_bottom' => 10
            ]);

            if ($this->nfse->cancellationCode) {
                $mpdf->SetWatermarkText('NFS-e Cancelada');
                $mpdf->showWatermarkText = true;
            }

            $mpdf->WriteHTML($html);
            return $mpdf->Output('NFse.pdf', $outputType);
        } catch (Exception $e) {
            throw new Exception("Falha ao gerar PDF: " . $e->getMessage());
        }
    }

    /**
     * Adds a "CANCELADA" watermark to the PDF if the cancellation code is present.
     * This is done by replacing the closing </body> tag with a style block that
     * creates a semi-transparent, 45-degree rotated, large text that says "CANCELADA".
     * This adds a visual mark to the PDF indicating that it's been cancelled.
     */
    private function addCancellationMark(): void
    {
        if ($this->nfse->cancellationCode) {
            $this->html = str_replace(
                '</body>',
                '<div style="position: fixed; opacity: 0.3; font-size: 72px; transform: rotate(-45deg); top: 50%; left: 25%;">CANCELADA</div></body>',
                $this->html
            );
        }
    }

    /**
     * Validates the output type to ensure it's a valid value.
     *
     * @param string $outputType Tipo de sa da. Valores poss veis: I (visualiza o direta),
     *                            D (download do arquivo PDF) e P (retorna o conte do do
     *                            PDF em uma vari vel).
     *
     * @throws \InvalidArgumentException Se o tipo de sa da for inv lido.
     */
    private function validateOutputType(string $outputType): void
    {
        if (!in_array($outputType, ['I', 'D', 'P'])) {
            throw new \InvalidArgumentException("Tipo de saída inválido: $outputType");
        }
    }
}
