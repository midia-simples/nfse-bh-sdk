<?php

namespace NFse\Service;

use Exception;
use NFse\Helpers\Utils;
use NFse\Models\Lot;
use NFse\Models\Settings;
use NFse\Signature\Subscriber;
use NFse\Soap\Soap;
use NFse\Soap\ErrorMsg;

class RpsToNFse
{
    private $settings;
    private $subscriber;
    private $soap;

    public function __construct(Settings $settings)
    {
        $this->settings = $settings;
        $this->subscriber = new Subscriber($settings);
        $this->subscriber->loadPFX();

        $this->soap = new Soap($settings, 'GerarNfseRequest');
    }

    /**
     * Gera uma NFSe a partir de uma RPS de um Lote
     *
     * @param Lot $lot
     * @return object
     */
    public function generateFromLot(Lot $lot): object
    {
        try {
            $rps = new Rps($this->settings, 'rps:' . $lot->rps->number);
            $rps->setRpsIdentification($lot);
            $rps->setService($lot);
            $rps->setProvider();
            $rps->setTaker($lot);

            $signedRpsXml = $rps->getSignedRps();

            // TODO: Criar arquivo de template XML
            $xmlLote = <<<XML
                <?xml version='1.0' encoding='UTF-8'?>
                <GerarNfseEnvio xmlns="http://www.abrasf.org.br/nfse.xsd">
                    <LoteRps Id="lote:{$lot->rpsLot}" versao="1.00">
                        <NumeroLote>{$lot->rpsLot}</NumeroLote>
                        <Cnpj>{$this->settings->issuer->cnpj}</Cnpj>
                        <InscricaoMunicipal>{$this->settings->issuer->imun}</InscricaoMunicipal>
                        <QuantidadeRps>1</QuantidadeRps>
                        <ListaRps>
                            {$signedRpsXml}
                        </ListaRps>
                    </LoteRps>
                </GerarNfseEnvio>
            XML;

            $signedXml = $this->subscriber->assina(Utils::xmlFilter($xmlLote), 'LoteRps');
            $this->soap->setXML($signedXml);
            $response = $this->soap->__soapCall();
            $xmlResponse = simplexml_load_string($response->outputXML);

            if (isset($xmlResponse->ListaMensagemRetorno)) {
                $errors = new ErrorMsg($xmlResponse);
                return (object)[
                    'success' => false,
                    'messages' => (object)$errors->getMessages(),
                ];
            }

            return (object)[
                'success' => true,
                'response' => $xmlResponse
            ];
        } catch (Exception $e) {
            return (object)[
                'success' => false,
                'messages' => [$e->getMessage()]
            ];
        }
    }
}
