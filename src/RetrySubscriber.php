<?php

namespace GuzzleHttp\Subscriber\Retry;

use GuzzleHttp\Event\SubscriberInterface;
use GuzzleHttp\Event\AbstractTransferStatsEvent;
use GuzzleHttp\Event\ErrorEvent;
use GuzzleHttp\Subscriber\Log\MessageFormatter;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * Plugin to automatically retry failed HTTP requests using filters a delay
 * strategy.
 */
class RetrySubscriber implements SubscriberInterface
{
    const RETRY_FORMAT = '[{ts}] {method} {url} - {code} {phrase} - Retries: {retries}, Delay: {delay}, Time: {connect_time}, {total_time}, Error: {error}';

    /** @var callable */
    private $filter;

    /** @var callable */
    private $delayFunc;

    /** @var int */
    private $maxRetries;

    /** @var callable */
    private $sleepFn;

    public static function getSubscribedEvents()
    {
        return [
            'complete' => ['onRequestSent'],
            'error'    => ['onRequestSent']
        ];
    }

    /**
     * @param callable $filter     Filter used to determine whether or not to retry a request. The filter must be a
     *                             callable that accepts the current number of retries and an AbstractTransferStatsEvent
     *                             object. The filter must return true or false to denote if the request must be retried
     * @param callable $delayFunc  Callable that accepts the number of retries and an AbstractTransferStatsEvent and
     *                             returns the amount of of time in seconds to delay.
     * @param int      $maxRetries Maximum number of retries
     * @param callable $sleepFn    Function invoked when the subscriber needs to sleep. Accepts a float containing the
     *                             amount of time in seconds to sleep and an AbstractTransferStatsEvent.
     */
    public function __construct(
        callable $filter,
        callable $delayFunc = null,
        $maxRetries = 5,
        callable $sleepFn = null
    ) {
        static $defaultDelayFunc = [__CLASS__, 'exponentialDelay'];
        $this->filter = $filter;
        $this->delayFunc = $delayFunc ?: $defaultDelayFunc;
        $this->maxRetries = $maxRetries;
        $this->sleepFn = $sleepFn ?: function($time) { usleep($time * 1000); };
    }

    public function onRequestSent(AbstractTransferStatsEvent $event)
    {
        $request = $event->getRequest();
        $retries = (int) $request->getConfig()->get('retries');

        if ($retries < $this->maxRetries) {
            $filterFn = $this->filter;
            if ($filterFn($retries, $event)) {
                $delayFn = $this->delayFunc;
                $sleepFn = $this->sleepFn;
                $sleepFn($delayFn($retries, $event), $event);
                $request->getConfig()->set('retries', ++$retries);
                $event->intercept($event->getClient()->send($request));
            }
        }
    }

    /**
     * Returns an exponential delay calculation
     *
     * @param int                        $retries Number of retries so far
     * @param AbstractTransferStatsEvent $event   Event containing transactional information
     *
     * @return int
     */
    public static function exponentialDelay(
        $retries,
        AbstractTransferStatsEvent $event
    ) {
        return (int) pow(2, $retries - 1);
    }

    /**
     * Creates a delay function that logs each retry before proxying to a
     * wrapped delay function.
     *
     * @param callable                $delayFn   Delay function to proxy to
     * @param LoggerInterface         $logger    Logger used to log messages
     * @param string|MessageFormatter $formatter Formatter to format messages
     *
     * @return callable
     */
    public static function createLoggingDelay(
        callable $delayFn,
        LoggerInterface $logger,
        $formatter = null
    ) {
        if (!$formatter) {
            $formatter = new MessageFormatter(self::RETRY_FORMAT);
        } elseif (!($formatter instanceof MessageFormatter)) {
            $formatter = new MessageFormatter($formatter);
        }

        return function ($retries, AbstractTransferStatsEvent $event) use ($delayFn, $logger, $formatter) {
            $delay = $delayFn($retries, $event);
            $logger->log(LogLevel::NOTICE, $formatter->format(
                $event->getRequest(),
                $event->getResponse(),
                $event instanceof ErrorEvent ? $event->getException() : null,
                ['retries' => $retries + 1, 'delay' => $delay] + $event->getTransferInfo()
            ));
            return $delay;
        };
    }

    /**
     * Creates a retry filter based on HTTP status codes
     *
     * @param array $failureStatuses Pass an array of status codes to override
     *     the default of [500, 503]
     *
     * @return callable
     */
    public static function createStatusFilter(array $failureStatuses = null)
    {
        $failureStatuses = $failureStatuses ?: [500, 503];
        $failureStatuses = array_fill_keys($failureStatuses, 1);

        return function ($retries, AbstractTransferStatsEvent $event) use ($failureStatuses) {
            if (!($response = $event->getResponse())) {
                return false;
            }
            return isset($failureStatuses[$response->getStatusCode()]);
        };
    }

    /**
     * Creates a retry filter based on cURL error codes.
     *
     * @param array $errorCodes Pass an array of curl error codes to override
     *     the default list of error codes.
     *
     * @return callable
     */
    public static function createCurlFilter($errorCodes = null)
    {
        $errorCodes = $errorCodes ?: [CURLE_COULDNT_RESOLVE_HOST,
            CURLE_COULDNT_CONNECT, CURLE_PARTIAL_FILE, CURLE_WRITE_ERROR,
            CURLE_READ_ERROR, CURLE_OPERATION_TIMEOUTED,
            CURLE_SSL_CONNECT_ERROR, CURLE_HTTP_PORT_FAILED, CURLE_GOT_NOTHING,
            CURLE_SEND_ERROR, CURLE_RECV_ERROR];

        $errorCodes = array_fill_keys($errorCodes, 1);

        return function ($retries, AbstractTransferStatsEvent $event) use ($errorCodes) {
            return isset($errorCodes[(int) $event->getTransferInfo('curl_result')]);
        };
    }

    /**
     * Creates a chain of callables that triggers one after the other until a
     * callable returns true (which results in a true return value), or a
     * callable short circuits the chain by returning -1 (resulting in a false
     * return value).
     *
     * @param array $filters Array of callables that accept the number of
     *   retries and an after send event and return true to retry the
     *   transaction, false to not retry and pass to the next filter in the
     *   chain, or -1 to not retry and to immediately break the chain.
     *
     * @return callable Returns a filter that can be used to determine if a
     *   transaction should be retried
     */
    public static function createChainFilter(array $filters)
    {
        return function ($retries, AbstractTransferStatsEvent $event) use ($filters) {
            foreach ($filters as $filter) {
                $result = $filter($retries, $event);
                if ($result === true) {
                    return true;
                } elseif ($result === -1) {
                    return false;
                }
            }

            return false;
        };
    }
}
