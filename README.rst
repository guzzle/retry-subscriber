=======================
Guzzle Retry Subscriber
=======================

Retries failed HTTP requests using customizable retry stragies.

Here's a simple example of how it's used:

.. code-block:: php

    use GuzzleHttp\Subscriber\Retry\RetrySubscriber;

    // Retry 500 and 503 responses
    $filter = RetrySubscriber::createStatusFilter();

    // Dely using exponential backoff
    $delayFn = ['GuzzleHttp\\Subscriber\\Retry\\RetrySubscriber', 'exponentialDelay'];

    // Retry up to 5 times
    $maxRetries = 5;

    $retry = new RetrySubscriber($filter, $delayFn, $maxRetries);

    $client = new GuzzleHttp\Client();
    $client->getEmitter()->addSubscriber($retry);

The retry subscriber invokes the provided ``$filter`` function to determine if
a request should be retried. When the filter is invoked, it is provided the
current retry count for the request and a ``GuzzleHttp\\Event\\CompleteEvent``.
The filter must then return true if the request should be retried, or false if
it should not be retried.

The RetrySubscriber provides several static functions that can be used to
easily create common retry filters for things like retrying specific HTTP
response codes, retrying specific cURL error codes (if cURL is installed), and
logging all retries using a PSR-3 logger, and creating a chain of retry
filters.

The second argument you must provide to the RetrySubscriber's constructor is a
callable function that returns the amount of time to wait (in seconds) before
retrying a request. You can use ``GuzzleHttp\\Subscriber\\Retry\\RetrySubscriber::exponentialDelay()``
if you want to just retry using exponential backoff.

You can also specify an optional max number of retries. If not specified, a
request can be retried up to 5 times before it is allowed to fail.

The final, optional, argument of the RetrySubscriber's constructor is a
function that is used to perform the actual sleep. This function accepts a
float representing the amount of time to sleep. If not provided, Guzzle will
just call ``usleep()``. It may be helpful when testing custom retry strategies
to provide a custom function that does not actuall perform a sleep.

