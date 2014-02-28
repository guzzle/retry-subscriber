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
request and a ``GuzzleHttp\\Event\\CompleteEvent`` or ``GuzzleHttp\\Event\\ErrorEvent``.
The filter must then return true if the request should be retried, or false if
it should not be retried.

Customizing the amount of delay
-------------------------------

The second argument provided to the RetrySubscriber's constructor is a callable
that is used to determine the amount of time to delay before retrying a request
that has been marked as needing a retry. This method accepts the current number
of retries and either a ``GuzzleHttp\\Event\\CompleteEvent`` or ``GuzzleHttp\\Event\\ErrorEvent``.
The function must then return an integer or float representing the amount of
time in seconds to sleep.

Omitting this argument will use a default exponential backoff strategy.

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
to provide a custom function that does not actuall perform a sleep.

