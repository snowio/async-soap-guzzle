<?php

namespace Meng\AsyncSoap\Guzzle;

use Meng\AsyncSoap\SoapClientInterface;
use Meng\Soap\HttpBinding\HttpBinding;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

class SoapClient implements SoapClientInterface
{
    private $httpBindingPromise;
    private $client;

    public function __construct(ClientInterface $client, PromiseInterface $httpBindingPromise)
    {
        $this->httpBindingPromise = $httpBindingPromise;
        $this->client = $client;
    }

    public function __call($name, $arguments)
    {
        return $this->callAsync($name, $arguments);
    }

    public function call($name, array $arguments, array $options = null, $inputHeaders = null, array &$outputHeaders = null)
    {
        $callPromise = $this->callAsync($name, $arguments, $options, $inputHeaders, $outputHeaders);
        return $callPromise->wait();
    }

    public function callAsync($name, array $arguments, array $options = null, $inputHeaders = null, array &$outputHeaders = null)
    {
        return \GuzzleHttp\Promise\coroutine(
            function () use ($name, $arguments, $options, $inputHeaders, &$outputHeaders) {
                /** @var HttpBinding $httpBinding */
                $httpBinding = (yield $this->httpBindingPromise);
                $request = $httpBinding->request($name, $arguments, $options, $inputHeaders);
                $requestOptions = isset($options['request_options']) ? $options['request_options'] : [];

                try {
                    $response = (yield $this->client->sendAsync($request, $requestOptions));
                    $this->tryLog($response, $options);
                    yield $this->interpretResponse($httpBinding, $response, $name, $outputHeaders);
                } catch (RequestException $exception) {
                    if ($exception->hasResponse()) {
                        $response = $exception->getResponse();
                        $this->tryLog($response, $options);
                        yield $this->interpretResponse($httpBinding, $response, $name, $outputHeaders);
                    } else {
                        throw $exception;
                    }
                } finally {
                    $request->getBody()->close();
                }
            }
        );
    }

    private function tryLog(ResponseInterface $response, array $options = null)
    {
        if ($options && isset($options['logger']) && $options['logger'] instanceof LoggerInterface) {
            $responseContents = $response->getBody()->__toString();

            $options['logger']->debug("Raw SOAP Response Received", [
                'response' => $responseContents
            ]);

            if (empty($responseContents)) {
                $options['logger']->warning("Empty response has been detected", [
                    'response' => $responseContents
                ]);
            }
        }
    }

    private function interpretResponse(HttpBinding $httpBinding, ResponseInterface $response, $name, &$outputHeaders)
    {
        return $httpBinding->response($response, $name, $outputHeaders);
    }
}
