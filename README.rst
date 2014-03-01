=======================
Guzzle Retry Subscriber
=======================

Retries failed HTTP requests using customizable retry stragies.

Here's a simple example of how it's used:

.. code-block:: php

    use GuzzleHttp\Subscriber\Retry\RetrySubscriber;

    // Retry 500 and 503 responses
    $filter = RetrySubscriber::createStatusFilter();
    $retry = new RetrySubscriber($filter);

    $client = new GuzzleHttp\Client();
    $client->getEmitter()->addSubscriber($retry);

Determining what should be retried
----------------------------------

The first argument of the RetrySubscriber's constructor is a callable that is
invoked to determine if a request should be retried.

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

    $filter = function (AbstractTransferEvent $event) {
        $resource = $event->getRequest()->getResource();
        // A response is not always received (e.g., for timeouts)
        $code = $event->getResponse()
            ? $event->getResponse()->getStatusCode()
            : null;

        return $resource == '/foo' && $code == 500;
    };

    $retry = new RetrySubscriber($filter);
    $client = new GuzzleHttp\Client();
    $client->getEmitter()->addSubscriber($retry);

Here's an example of using a more customizable retry chain. This example retries
faile 500 and 503 responses for only idempotent GET and HEAD requests. Retry
chains are created using the static ``RetrySubscriber::createFilterChain()``
method. This method accepts an array of callable filters that are each invoked
one after the other until a filter returns ``true`` (meaning the request should
be retried), a filter returns ``-1`` (meaning the chain should be broken and
the request should not be retried), or the last filter has been called.

.. code-block:: php

    use GuzzleHttp\Event\AbstractTransferEvent;
    use GuzzleHttp\Subscriber\Retry\RetrySubscriber;

    // Retry 500 and 503 responses that were sent as GET and HEAD requests.
    $filter = RetrySubscriber::createChainFilter([
        function (AbstractTransferEvent $event) {
            $method = $event->getRequests()
                ? $event->getRequest()->getMethod()
                : null;

            // Break the filter if it was not an idempotent request
            if (!in_array($method, ['GET', 'HEAD'])) {
                return -1;
            }

            // Otherwise, defer to subsequent filters
            return false;
        },
        // Performs the last check, returning ``true`` or ``false`` based on
        // if the response received a 500 or 503 status code.
        RetrySubscriber::createStatusFilter([500, 503])
    ]);

    $retry = new RetrySubscriber($filter);
    $client = new GuzzleHttp\Client();
    $client->getEmitter()->addSubscriber($retry);

Customizing the amount of delay
-------------------------------

The second argument provided to the RetrySubscriber's constructor is a callable
that is used to determine the amount of time to delay before retrying a request
that has been marked as needing a retry. This method accepts the current number
of retries and either a ``GuzzleHttp\Event\CompleteEvent`` or a
``GuzzleHttp\Event\ErrorEvent``. The function must then return an integer or
float representing the amount of time in seconds to sleep.

Omitting this argument will use a default exponential backoff strategy.

Here's an example of creating a custom delay that always delays for 1 second:

.. code-block:: php

    use GuzzleHttp\Subscriber\Retry\RetrySubscriber;

    $filter = RetrySubscriber::createStatusFilter();
    $delayFn = function ($number, $event) { return 1; };
    $retry = new RetrySubscriber($filter, $delayFn);

Changing the max number of retries
----------------------------------

You can also specify an optional max number of retries in the third argument of
the RetrySubscriber's constructor. If not specified, a request can be retried
up to 5 times before it is allowed to fail.

Testing without sleeping
------------------------

The final, optional, argument of the RetrySubscriber's constructor is a
function that is used to perform the actual sleep. This function accepts a
float representing the amount of time to sleep. If not provided, Guzzle will
just call ``usleep()``. It may be helpful when testing custom retry strategies
to provide a custom function that does not actually perform a sleep.

Here's an example of creating a retry subscriber that doesn't actually perform
a sleep when it is told to sleep.

.. code-block:: php

    use GuzzleHttp\Subscriber\Retry\RetrySubscriber;

    $filter = RetrySubscriber::createStatusFilter();
    $sleepFn = function ($time) { return; };
    $retry = new RetrySubscriber($filter, null, 5, $sleepFn);
