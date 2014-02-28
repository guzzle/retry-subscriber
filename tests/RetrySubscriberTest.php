<?php

namespace GuzzleHttp\Tests\Subscriber\RetrySubscriber;

use GuzzleHttp\Client;
use GuzzleHttp\Message\Request;
use GuzzleHttp\Message\RequestInterface;
use GuzzleHttp\Message\Response;
use GuzzleHttp\Message\ResponseInterface;
use GuzzleHttp\Subscriber\History;
use GuzzleHttp\Subscriber\Mock;
use GuzzleHttp\Subscriber\Log\SimpleLogger;
use GuzzleHttp\Subscriber\Retry\RetrySubscriber;

class RetrySubscriberTest extends \PHPUnit_Framework_TestCase
{
    public function testCreatesDefaultStatusFilter()
    {
        $f = RetrySubscriber::createStatusFilter();
        $e = $this->createEvent(new Response(500));
        $this->assertTrue($f(1, $e));
        $e = $this->createEvent(new Response(503));
        $this->assertTrue($f(0, $e));
        $e = $this->createEvent(new Response(200));
        $this->assertFalse($f(1, $e));
    }

    public function testCreatesCustomStatusFilter()
    {
        $f = RetrySubscriber::createStatusFilter([202, 304]);
        $e = $this->createEvent(new Response(500));
        $this->assertFalse($f(1, $e));
        $e = $this->createEvent(new Response(503));
        $this->assertFalse($f(0, $e));
        $e = $this->createEvent(new Response(202));
        $this->assertTrue($f(1, $e));
        $e = $this->createEvent();
        $this->assertFalse($f(1, $e));
    }

    public function testCreatesDefaultCurlFilter()
    {
        $f = RetrySubscriber::createCurlFilter();
        $e = $this->createEvent(null, null, null, ['curl_result' => CURLE_RECV_ERROR]);
        $this->assertTrue($f(1, $e));
        $e = $this->createEvent(null, null, null, ['curl_result' => CURLE_OK]);
        $this->assertFalse($f(0, $e));
    }

    public function testCreatesCustomCurlFilter()
    {
        $f = RetrySubscriber::createCurlFilter([CURLE_OK]);
        $e = $this->createEvent(null, null, null, ['curl_result' => CURLE_RECV_ERROR]);
        $this->assertFalse($f(1, $e));
        $e = $this->createEvent(null, null, null, ['curl_result' => CURLE_OK]);
        $this->assertTrue($f(0, $e));
    }

    public function testCreatesChainFilter()
    {
        $e = $this->createEvent(new Response(500));
        $f = RetrySubscriber::createChainFilter([
            function () { return false; },
            function () { return true; },
        ]);
        $this->assertTrue($f(1, $e));
        $f = RetrySubscriber::createChainFilter([function () { return false; }]);
        $this->assertFalse($f(1, $e));
        $f = RetrySubscriber::createChainFilter([function () { return true; }]);
        $this->assertTrue($f(1, $e));
    }

    public function testCreateLoggingDelayFilter()
    {
        $str = fopen('php://temp', 'r+');
        $l = new SimpleLogger($str);
        $e = $this->createEvent(new Response(500));
        $f = RetrySubscriber::createLoggingDelay(function () {
            return true;
        }, $l);
        $this->assertTrue($f(2, $e));
        rewind($str);
        $this->assertContains('500 Internal Server Error - Retries: 3, Delay: 1', stream_get_contents($str));
    }

    public function testCreateLoggingDelayFilterWithCustomFormat()
    {
        $str = fopen('php://temp', 'r+');
        $l = new SimpleLogger($str);
        $e = $this->createEvent(new Response(500));
        $f = RetrySubscriber::createLoggingDelay(function () {
            return true;
        }, $l, 'Foo');
        $this->assertTrue($f(2, $e));
        rewind($str);
        $this->assertContains('Foo', stream_get_contents($str));
    }

    public function testCalculatesExponentialDelay()
    {
        $e = $this->createEvent(new Response(500));
        $this->assertEquals(0, RetrySubscriber::exponentialDelay(0, $e));
        $this->assertEquals(1, RetrySubscriber::exponentialDelay(1, $e));
        $this->assertEquals(2, RetrySubscriber::exponentialDelay(2, $e));
        $this->assertEquals(4, RetrySubscriber::exponentialDelay(3, $e));
        $this->assertEquals(8, RetrySubscriber::exponentialDelay(4, $e));
    }

    private function createEvent(
        ResponseInterface $response = null,
        RequestInterface $request = null,
        \Exception $exception = null,
        array $transferInfo = [],
        $type = 'GuzzleHttp\Event\AbstractTransferStatsEvent'
    ) {
        if (!$request) {
            $request = new Request('GET', 'http://www.foo.com');
        }

        $e = $this->getMockBuilder($type)
            ->setMethods(['getResponse', 'getTransferInfo', 'getRequest', 'getException'])
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();
        $e->expects($this->any())
            ->method('getRequest')
            ->will($this->returnValue($request));
        $e->expects($this->any())
            ->method('getResponse')
            ->will($this->returnValue($response));
        $e->expects($this->any())
            ->method('getException')
            ->will($this->returnValue($exception));
        $e->expects($this->any())
            ->method('getTransferInfo')
            ->will($this->returnCallback(function ($arg) use ($transferInfo) {
                return $arg ? (isset($transferInfo[$arg]) ? $transferInfo[$arg] : null) : $transferInfo;
            }));

        return $e;
    }
}
