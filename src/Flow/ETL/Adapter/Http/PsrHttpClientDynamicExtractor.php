<?php

declare(strict_types=1);

namespace Flow\ETL\Adapter\Http;

use function Flow\ETL\DSL\{array_entry, str_entry};
use Flow\ETL\Adapter\Http\DynamicExtractor\NextRequestFactory;
use Flow\ETL\{Extractor, FlowContext, Row, Rows};
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\{RequestInterface, ResponseInterface};

final class PsrHttpClientDynamicExtractor implements Extractor
{
    /**
     * @psalm-var callable(RequestInterface, ResponseInterface) : void|null
     *
     * @var null|callable(RequestInterface, ResponseInterface) : void
     */
    private $postRequest;

    /**
     * @psalm-var callable(RequestInterface) : void|null
     *
     * @var null|callable(RequestInterface) : void
     */
    private $preRequest;

    /**
     * @psalm-param callable(RequestInterface) : void|null $preRequest
     * @psalm-param callable(RequestInterface, ResponseInterface) : void|null $postRequest
     */
    public function __construct(private readonly ClientInterface $client, private readonly NextRequestFactory $requestFactory, ?callable $preRequest = null, ?callable $postRequest = null)
    {
        $this->preRequest = $preRequest;
        $this->postRequest = $postRequest;
    }

    public function extract(FlowContext $context) : \Generator
    {
        $responseFactory = new ResponseEntriesFactory();
        $requestFactory = new RequestEntriesFactory();

        $nextRequest = $this->requestFactory->create();

        $shouldPutInputIntoRows = $context->config->shouldPutInputIntoRows();

        while ($nextRequest) {
            if ($this->preRequest) {
                ($this->preRequest)($nextRequest);
            }

            $response = $this->client->sendRequest($nextRequest);

            if ($this->postRequest) {
                ($this->postRequest)($nextRequest, $response);
            }

            if ($shouldPutInputIntoRows) {
                $signal = yield new Rows(
                    Row::create(
                        ...\array_merge(
                            $responseFactory->create($response)->all(),
                            $requestFactory->create($nextRequest)->all(),
                            [
                                str_entry('request_uri', (string) $nextRequest->getUri()),
                                str_entry('request_method', $nextRequest->getMethod()),
                                array_entry('request_headers', $nextRequest->getHeaders()),
                            ]
                        )
                    )
                );

                if ($signal === Extractor\Signal::STOP) {
                    return;
                }
            } else {
                $signal = yield new Rows(
                    Row::create(...\array_merge($responseFactory->create($response)->all(), $requestFactory->create($nextRequest)->all()))
                );

                if ($signal === Extractor\Signal::STOP) {
                    return;
                }
            }

            $nextRequest = $this->requestFactory->create($response);
        }
    }
}
