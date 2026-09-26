<?php

namespace justinholtweb\erpysapb1\transport;

use Craft;
use GuzzleHttp\Client;
use justinholtweb\erpy\base\Response;
use justinholtweb\erpy\base\Transport;

/**
 * Erpy's transport, able to talk to a Service Layer with a self-signed certificate.
 *
 * Erpy's `Transport` builds its Guzzle client through `Craft::createGuzzleClient()` and offers no
 * way to pass client options, so certificate verification cannot be switched off by
 * configuration. What it can reach is the Yii container `createGuzzleClient()` instantiates the
 * client through. For the duration of one request — and only when the merchant has said the
 * certificate is self-signed — this registers a definition that builds the client with
 * `verify => false`, then puts the container back exactly as it was. Nothing else in the process
 * ever sees an unverified client.
 *
 * Everything else — retries, logging, redaction, throttling, the test double — is the parent's,
 * untouched, because `request()` is the only thing overridden.
 */
class ServiceLayerTransport extends Transport
{
    private bool $skipCertificateCheck = false;

    public function skipCertificateCheck(bool $value = true): self
    {
        $this->skipCertificateCheck = $value;

        return $this;
    }

    public function request(string $method, string $uri, array $options = []): Response
    {
        $container = Craft::$container;

        // A test double never builds a client. Nor is there anything to do with a Client that is
        // already a registered singleton: the container would hand that back regardless.
        if (!$this->skipCertificateCheck || $this->hasDouble() || $container->hasSingleton(Client::class)) {
            return parent::request($method, $uri, $options);
        }

        $previous = $container->getDefinitions()[Client::class] ?? null;

        $container->set(Client::class, static function($container, array $params): Client {
            $config = is_array($params[0] ?? null) ? $params[0] : [];
            $config['verify'] = false;

            return new Client($config);
        });

        try {
            return parent::request($method, $uri, $options);
        } finally {
            if ($previous === null) {
                $container->clear(Client::class);
            } else {
                $container->set(Client::class, $previous);
            }
        }
    }
}
