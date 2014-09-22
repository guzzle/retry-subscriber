=======================
Guzzle Retry Subscriber
=======================

Retries failed HTTP requests using customizable retry strategies.

Here's a simple example of how it's used:

.. code-block:: php

    use GuzzleHttp\Subscriber\Retry\RetrySubscriber;

    // Retry 500 and 503 responses
    $retry = new RetrySubscriber([
        'filter' => RetrySubscriber::createStatusFilter()
    ]);

    $client = new GuzzleHttp\Client();
    $client->getEmitter()->attach($retry);

Installing
----------

Add the following to your composer.json:

.. code-block:: javascript

    {
        "require": {
            "guzzlehttp/retry-subscriber": "~1.0"
        }
    }

Creating a RetrySubscriber
--------------------------

The constructor of the RetrySubscriber accepts an associative array of
configuration options:

filter
    (callable) (Required) Filter used to determine whether or not to retry a
    request. The filter must be a callable that accepts the current number of
    retries as the first argument and a
    ``GuzzleHttp\Event\AbstractTransferEvent`` object as the second argument.
    The filter must return ``true`` or ``false`` to denote if the request must be
    retried.
delay
    Callable that accepts the number of retries as the first argument and a
    ``GuzzleHttp\Event\AbstractTransferEvent`` as the second argument. The
    callable must return the amount of of time (in milliseconds) to delay.

    If no ``delay`` configuration value is provided, then a default exponential
    backoff implementation is used.
max
    Integer representing the maximum number of retries to allow before giving
    up.

    If no ``max`` configuration value is provided, then a request will be
    retried 5 times.

Determining what should be retried
----------------------------------

The required ``filter`` option of the RetrySubscriber's constructor is a
callable that is invoked to determine if a request should be retried.

When the filter is invoked, it is provided the current retry count for the
request and a ``GuzzleHttp\Event\CompleteEvent`` or
``GuzzleHttp\Event\ErrorEvent`` (both events extend from
``GuzzleHttp\Event\AbstractTransferEvent``, so you should typehint on that).
The filter must then return true if the request should be retried, or false if
it should not be retried.

Here's an example of retrying failed 500 responses sent to the ``/foo``
endpoint:

.. code-block:: php

    use GuzzleHttp\Event\AbstractTransferEvent;
    use GuzzleHttp\Subscriber\Retry\RetrySubscriber;

    $retry = new RetrySubscriber([
        'filter' => function ($retries, AbstractTransferEvent $event) {
            $resource = $event->getRequest()->getResource();
            // A response is not always received (e.g., for timeouts)
            $code = $event->getResponse()
                ? $event->getResponse()->getStatusCode()
                : null;

            return $resource == '/foo' && $code == 500;
        }
    ]);

    $client = new GuzzleHttp\Client();
    $client->getEmitter()->attach($retry);

Filter Chains
~~~~~~~~~~~~~

You can create more customizable retry logic with filter chains, which are
created using the static ``RetrySubscriber::createFilterChain()`` method. This
method accepts an array of callable filters that are each invoked one after the
other. The filters in the chain should return one of the following values,
which affects how the rest of the chain is executed.

* ``RetrySubscriber::RETRY`` (i.e., ``true``) – Retry the request.
* ``RetrySubscriber::DEFER`` (i.e., ``false``) – Defer to the next filter in
  the chain.
* ``RetrySubscriber::BREAK_CHAIN`` (i.e., ``-1``) – Stop the filter chain, and
  do **not** retry the request.

Here's an example using filter chains that retries failed 500 and 503 responses
for only idempotent or "safe" requests as defined by
`RFC 7231 <http://tools.ietf.org/html/rfc7231#section-4.2.2>`_.

.. code-block:: php

    use GuzzleHttp\Event\AbstractTransferEvent;
    use GuzzleHttp\Subscriber\Retry\RetrySubscriber;

    // Retry 500 and 503 responses that were sent as GET and HEAD requests.
    $filter = RetrySubscriber::createChainFilter([
        // Does early filter to force non-idempotent methods to NOT be retried.
        RetrySubscriber::createIdempotentFilter(),
        // Performs the last check, returning ``true`` or ``false`` based on
        // if the response received a 500 or 503 status code.
        RetrySubscriber::createStatusFilter([500, 503])
    ]);

    $retry = new RetrySubscriber(['filter' => $filter]);
    $client = new GuzzleHttp\Client();
    $client->getEmitter()->attach($retry);

Customizing the amount of delay
-------------------------------

``delay`` is an optional configuration option in the RetrySubscriber's
constructor that is a callable used to determine the amount of time to delay
before retrying a request that has been marked as needing a retry. The callable
accepts the current number of retries and either a
``GuzzleHttp\Event\CompleteEvent`` or a ``GuzzleHttp\Event\ErrorEvent``. The
function must then return an integer or float representing the amount of time
in milliseconds to sleep.

.. note::

    Omitting this argument will use a default exponential backoff strategy.

Here's an example of creating a custom delay that always delays for 1 millisecond:

.. code-block:: php

    use GuzzleHttp\Subscriber\Retry\RetrySubscriber;

    $retry = new RetrySubscriber([
        'filter' => RetrySubscriber::createStatusFilter(),
        'delay'  => function ($number, $event) { return 1; }
    ]);

Changing the max number of retries
----------------------------------

You can also specify an optional max number of retries in the ``max``
configuration option of the RetrySubscriber's constructor. If not specified, a
request can be retried up to 5 times before it is allowed to fail.

.. code-block:: php

    use GuzzleHttp\Subscriber\Retry\RetrySubscriber;

    $retry = new RetrySubscriber([
        'filter' => RetrySubscriber::createStatusFilter(),
        'max'    => 3
    ]);
